<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Authentication;

use Drupal\coffee_journal_oauth\OAuthServer;
use Drupal\Core\Authentication\{AuthenticationProviderInterface, AuthenticationProviderFilterInterface};
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth access tokens cannot become browser sessions or authorize generic APIs.
 */
final class JournalOAuth implements AuthenticationProviderInterface, AuthenticationProviderFilterInterface {

  public function __construct(private readonly OAuthServer $server) {}

  public function applies(Request $request) {
    return $request->getPathInfo() === '/api/integrations/v1/mcp'
      && preg_match('/^Bearer (?!cj_)/i', $request->headers->get('Authorization', ''));
  }

  public function authenticate(Request $request) {
    if (!preg_match('/^Bearer (\S+)$/Di', $request->headers->get('Authorization', ''), $match)) { return NULL; }
    $connection = $this->server->verify($match[1]);
    if (!$connection) { return NULL; }
    $request->attributes->set('_coffee_connection', $connection);
    return $connection['account'];
  }

  public function appliesToRoutedRequest(Request $request, $authenticated) {
    return in_array('coffee_oauth', $request->attributes->get('_route_object')?->getOption('_auth') ?? [], TRUE);
  }
}
