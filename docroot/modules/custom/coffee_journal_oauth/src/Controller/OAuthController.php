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
 * Discovery and consent/code exchange for one explicitly configured client.
 */
final class OAuthController extends ControllerBase {

  public function __construct(private readonly OAuthSettings $settings, private readonly OAuthServer $server, private readonly GrantStore $grants, private readonly FloodInterface $flood) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_oauth.settings'), $container->get('coffee_journal_oauth.server'), $container->get('coffee_journal_oauth.grants'), $container->get('flood'));
  }

  private function available(Request $request, bool $discovery = FALSE): ?JsonResponse {
    if (!($discovery ? $this->settings->discoveryEnabled() : $this->settings->enabled())) {
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
    if ($failure = $this->available($request, TRUE)) { return $failure; }
    $issuer = $this->settings->issuer();
    $data = $kind === 'resource' ? [
      'resource' => $this->settings->resource(), 'authorization_servers' => [$issuer], 'scopes_supported' => OAuthSettings::SCOPES,
    ] : [
      'issuer' => $issuer, 'authorization_endpoint' => $issuer . '/coffee/oauth/authorize', 'token_endpoint' => $issuer . '/coffee/oauth/token',
      'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
      'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => OAuthSettings::SCOPES,
      'token_endpoint_auth_methods_supported' => $this->settings->get('client_secret') === '' ? ['none'] : ['client_secret_post', 'client_secret_basic'],
    ];
    return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
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
      return $this->server->error($exception);
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

  public function connection(Request $request): Response {
    if ($failure = $this->available($request)) { return $failure; }
    try {
      if ($request->isMethod('DELETE')) {
        $this->grants->disconnect($this->currentUser());
      }
      return new JsonResponse(['data' => $this->grants->status($this->currentUser())], 200, ['Cache-Control' => 'private, no-store']);
    }
    catch (OAuthServerException $exception) {
      return $this->server->error($exception);
    }
  }
}
