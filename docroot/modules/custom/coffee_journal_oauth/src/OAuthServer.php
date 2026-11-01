<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\coffee_journal_oauth\Entity\User;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\{IssuedBy, PermittedFor, SignedWith, StrictValidAt};
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\{AuthCodeGrant, RefreshTokenGrant};
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configures League's grants and adds the journal's resource/client policy.
 */
final class OAuthServer {

  public function __construct(
    private readonly OAuthSettings $settings,
    private readonly Repositories $repositories,
    private readonly GrantStore $store,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
  ) {}

  public function server(): AuthorizationServer {
    if (!$this->settings->enabled()) {
      throw OAuthServerException::accessDenied('OAuth is not enabled.');
    }
    $key = $this->settings->get('encryption_key');
    if (!is_string($key) || strlen($key) < 64) {
      throw new \LogicException('Configure an independent OAuth encryption secret of at least 64 characters.');
    }
    $server = new AuthorizationServer($this->repositories, $this->repositories, $this->repositories,
      new CryptKey($this->settings->keyPath('private')), $key);
    $code = new AuthCodeGrant($this->repositories, $this->repositories, new \DateInterval('PT5M'));
    $code->setRefreshTokenTTL(new \DateInterval('P30D'));
    // Public clients must receive a rotated refresh token, and the consumed
    // one must stay revoked so reuse revokes the whole family.
    $refresh = new RefreshTokenGrant($this->repositories);
    $refresh->setRefreshTokenTTL(new \DateInterval('P30D'));
    $server->enableGrantType($code, new \DateInterval('PT15M'));
    $server->enableGrantType($refresh, new \DateInterval('PT15M'));
    $server->setDefaultScope('beans:read');
    return $server;
  }

  /**
   * Validates the request against the specific provider client it names.
   */
  public function authorize(array $query): AuthorizationRequestInterface {
    foreach ($query as $value) {
      if (!is_string($value)) { throw OAuthServerException::invalidRequest('authorization parameters'); }
    }
    $client = is_string($query['client_id'] ?? NULL) ? $this->settings->client($query['client_id']) : NULL;
    if (!$client || isset($query['destination']) || ($query['resource'] ?? NULL) !== $this->settings->resource()
      || ($query['code_challenge_method'] ?? '') !== 'S256'
      || !is_string($query['code_challenge'] ?? NULL) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $query['code_challenge'])
      || !is_string($query['state'] ?? NULL) || strlen($query['state']) < 8 || strlen($query['state']) > 2048
      || !in_array($query['redirect_uri'] ?? NULL, $client['redirects'], TRUE)) {
      throw OAuthServerException::invalidRequest('client_id, resource, redirect_uri, state or PKCE');
    }
    return $this->server()->validateAuthorizationRequest((new ServerRequest('GET', $this->settings->issuer() . '/coffee/oauth/authorize'))->withQueryParams($query));
  }

  public function complete(array $query, AccountInterface $account, bool $approved): Response {
    $request = $this->authorize($query);
    $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $request->getScopes());
    $grant = $approved ? $this->store->consent($account, $scopes, $query['client_id']) : 'denied';
    $request->setUser(new User($grant));
    $request->setAuthorizationApproved($approved);
    try {
      return $this->withIssuer($this->response($this->server()->completeAuthorizationRequest($request, new PsrResponse())));
    }
    catch (OAuthServerException $exception) {
      // Denials and late failures still redirect, so they also carry iss.
      return $this->withIssuer($this->error($exception));
    }
  }

  /**
   * Adds the RFC 9207 iss parameter to every authorization redirect.
   *
   * Clients compare it against the issuer they sent the request to, which
   * blocks mix-up attacks. It must appear on success and error responses
   * alike, and only on redirects to the client's callback.
   */
  private function withIssuer(Response $response): Response {
    $location = $response->headers->get('Location');
    if (!$response->isRedirection() || !is_string($location) || $location === '') {
      return $response;
    }
    $parts = parse_url($location);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https') {
      return $response;
    }
    parse_str($parts['query'] ?? '', $query);
    if (isset($query['iss'])) {
      return $response;
    }
    $query['iss'] = $this->settings->issuer();
    $rebuilt = $parts['scheme'] . '://' . $parts['host']
      . (isset($parts['port']) ? ':' . $parts['port'] : '')
      . ($parts['path'] ?? '') . '?' . http_build_query($query)
      . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    $response->headers->set('Location', $rebuilt);
    return $response;
  }

  public function token(array $body, array $headers = []): Response {
    $clientId = $body['client_id'] ?? NULL;
    if (!is_string($clientId) || !$this->settings->client($clientId)
      || ($body['resource'] ?? NULL) !== $this->settings->resource()
      || !in_array($body['grant_type'] ?? NULL, ['authorization_code', 'refresh_token'], TRUE)) {
      return $this->error(OAuthServerException::invalidRequest('client_id, resource or grant_type'));
    }
    $key = 'coffee_oauth_exchange:' . hash('sha256', $clientId);
    // Serializes one-time code/refresh consumption across workers.
    if (!$this->lock->acquire($key, 120.0)) {
      return new JsonResponse(['error' => 'temporarily_unavailable'], 503, ['Retry-After' => '2', 'Cache-Control' => 'no-store']);
    }
    $transaction = $this->database->startTransaction();
    try {
      $request = (new ServerRequest('POST', $this->settings->issuer() . '/coffee/oauth/token', $headers))->withParsedBody($body);
      return $this->response($this->server()->respondToAccessTokenRequest($request, new PsrResponse()));
    }
    catch (OAuthServerException $exception) {
      // Commit invalid refresh reuse detection so the entire grant stays revoked.
      return $this->error($exception);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    finally {
      unset($transaction);
      $this->lock->release($key);
    }
  }

  public function verify(string $bearer): ?array {
    if (!$this->settings->enabled() || strlen($bearer) > 16384) {
      return NULL;
    }
    try {
      $signer = new Sha256();
      $publicKey = InMemory::file($this->settings->keyPath('public'));
      $config = Configuration::forAsymmetricSigner($signer, InMemory::plainText('unused'), $publicKey);
      $token = $config->parser()->parse($bearer);
      $signed = $token instanceof UnencryptedToken && $config->validator()->validate($token, new SignedWith($signer, $publicKey));
      if (!$signed && $token instanceof UnencryptedToken && $this->settings->get('previous_public_key') !== '') {
        $signed = $config->validator()->validate($token, new SignedWith($signer, InMemory::file($this->settings->keyPath('previous_public'))));
      }
      $clock = new class implements \Psr\Clock\ClockInterface {
        public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
      };
      if (!$signed || !$config->validator()->validate($token,
        new IssuedBy($this->settings->issuer()),
        new PermittedFor($this->settings->resource()), new StrictValidAt($clock))) {
        return NULL;
      }
      $claims = $token->claims();
      $id = $claims->get('jti');
      $grantId = $claims->get('sub');
      $scopes = $claims->get('scopes');
      if (!is_string($id) || !is_string($grantId) || !is_array($scopes) || !$scopes
        || $this->store->revoked('access', $id) || !($grant = $this->store->grant($grantId, TRUE))
        || $claims->get('client_id') !== $grant['client_id']
        || ($this->store->record('access', $id)['grant'] ?? NULL) !== $grantId
        || array_diff($scopes, $grant['scopes'])) {
        return NULL;
      }
      $grant['scopes'] = $scopes;
      $grant['oauth_challenge'] = $this->settings->challenge();
      return $grant;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  public function error(OAuthServerException $exception): Response {
    // Do not log authorization codes, token bodies or private request values.
    return $this->response($exception->generateHttpResponse(new PsrResponse()));
  }

  /**
   * Error responses from the authorize endpoint must also identify the issuer.
   */
  public function authorizeError(OAuthServerException $exception): Response {
    return $this->withIssuer($this->error($exception));
  }

  private function response(\Psr\Http\Message\ResponseInterface $response): Response {
    $headers = $response->getHeaders();
    $headers['Cache-Control'] = 'private, no-store';
    $headers['Pragma'] = 'no-cache';
    $headers['Referrer-Policy'] = 'no-referrer';
    return new Response((string) $response->getBody(), $response->getStatusCode(), $headers);
  }
}
