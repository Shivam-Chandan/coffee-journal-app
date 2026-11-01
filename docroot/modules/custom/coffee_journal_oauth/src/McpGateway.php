<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\coffee_journal_api\Service\{ConnectorAccess, JournalMcp};
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};

/**
 * Public protocol discovery carries no personal identity or journal access.
 */
final class McpGateway {

  public function __construct(private readonly OAuthSettings $settings, private readonly ConnectorAccess $access, private readonly JournalMcp $mcp, private readonly FloodInterface $flood) {}

  public function enabled(): bool { return $this->settings->enabled(); }

  public function handle(Request $request): Response {
    if ($request->attributes->has('_coffee_connection')) {
      return $this->access->authorize($request) ?? $this->mcp->handle($request, $request->attributes->get('_coffee_connection'));
    }
    $challenge = $this->settings->challenge();
    // A 401 with the metadata pointer is what starts each client's sign-in.
    if ($request->headers->has('Authorization')) {
      return new JsonResponse(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => $challenge, 'Cache-Control' => 'private, no-store']);
    }
    $ip = hash('sha256', (string) $request->getClientIp());
    if (!$this->flood->isAllowed('coffee_oauth_discovery', 30, 60, $ip)) {
      return new JsonResponse(['error' => 'rate_limited'], 429, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
    }
    $this->flood->register('coffee_oauth_discovery', 60, $ip);
    // Anonymous discovery advertises tool definitions only; no provider identity
    // is assumed and no journal data is reachable without a verified grant.
    return $this->mcp->handle($request, [
      'id' => 'anonymous_' . $ip, 'scopes' => OAuthSettings::SCOPES, 'oauth_challenge' => $challenge,
    ]);
  }
}
