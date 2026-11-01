<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Controller;

use Drupal\coffee_journal_oauth\Form\ConsentForm;
use Drupal\coffee_journal_oauth\{GrantStore, OAuthServer, OAuthSettings};
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Url;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};

/**
 * Discovery and consent/code exchange for the explicitly configured clients.
 */
final class OAuthController extends ControllerBase {

  public function __construct(private readonly OAuthSettings $settings, private readonly OAuthServer $server, private readonly GrantStore $grants, private readonly FloodInterface $flood) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_oauth.settings'), $container->get('coffee_journal_oauth.server'), $container->get('coffee_journal_oauth.grants'), $container->get('flood'));
  }

  private function available(Request $request): ?JsonResponse {
    if (!$this->settings->enabled()) {
      return new JsonResponse(['error' => 'not_found'], 404, ['Cache-Control' => 'no-store']);
    }
    $id = hash('sha256', (string) $request->getClientIp());
    if (!$this->flood->isAllowed('coffee_oauth_http', 120, 60, $id)) {
      return new JsonResponse(['error' => 'temporarily_unavailable'], 429, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
    }
    $this->flood->register('coffee_oauth_http', 60, $id);
    return NULL;
  }

  public function discovery(Request $request, string $kind): Response {
    if ($failure = $this->available($request)) { return $failure; }
    $issuer = $this->settings->issuer();
    if ($kind === 'resource') {
      return new JsonResponse([
        'resource' => $this->settings->resource(), 'authorization_servers' => [$issuer], 'scopes_supported' => OAuthSettings::SCOPES,
      ], 200, ['Cache-Control' => 'private, no-store']);
    }
    // Advertise every method a configured client can actually use, so a public
    // PKCE client and a confidential client can both be registered.
    $methods = [];
    foreach ($this->settings->clients() as $client) {
      $methods = array_merge($methods, $client['secret'] === '' ? ['none'] : ['client_secret_post', 'client_secret_basic']);
    }
    return new JsonResponse([
      'issuer' => $issuer, 'authorization_endpoint' => $issuer . '/coffee/oauth/authorize', 'token_endpoint' => $issuer . '/coffee/oauth/token',
      'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
      'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => OAuthSettings::SCOPES,
      'token_endpoint_auth_methods_supported' => array_values(array_unique($methods)),
      // Every authorization redirect carries iss, so clients can use a stable
      // redirect URI instead of a per-connection callback.
      'authorization_response_iss_parameter_supported' => TRUE,
    ], 200, ['Cache-Control' => 'private, no-store']);
  }

  public function authorize(Request $request): array|Response {
    if ($failure = $this->available($request)) { return $failure; }
    try {
      $query = $request->query->all();
      $this->server->authorize($query);
      if ($this->currentUser()->isAnonymous()) {
        $destination = Url::fromRoute('coffee_journal_oauth.authorize', [], ['query' => $query])->toString();
        return new RedirectResponse(Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]])->toString(), 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
      }
      return $this->formBuilder()->getForm(ConsentForm::class, $query);
    }
    catch (OAuthServerException $exception) {
      return $this->server->authorizeError($exception);
    }
  }

  public function token(Request $request): Response {
    if ($failure = $this->available($request)) { return $failure; }
    if (strlen($request->getContent()) > 16384 || $request->getContentTypeFormat() !== 'form') {
      return new JsonResponse(['error' => 'invalid_request'], 400, ['Cache-Control' => 'no-store']);
    }
    $body = $request->request->all();
    foreach ($body as $value) {
      if (!is_string($value)) { return new JsonResponse(['error' => 'invalid_request'], 400, ['Cache-Control' => 'no-store']); }
    }
    return $this->server->token($body, $request->headers->all());
  }

  /**
   * Browser-owned status, and revocation of one named provider's grants.
   */
  public function connection(Request $request, string $provider = ''): Response {
    if ($failure = $this->available($request)) { return $failure; }
    try {
      if ($request->isMethod('DELETE')) {
        $this->grants->disconnect($this->currentUser(), $provider);
      }
      return new JsonResponse(['data' => $this->grants->status($this->currentUser())], 200, ['Cache-Control' => 'private, no-store']);
    }
    catch (OAuthServerException $exception) {
      return $this->server->error($exception);
    }
  }
}
