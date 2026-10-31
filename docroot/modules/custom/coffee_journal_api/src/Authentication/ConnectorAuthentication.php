<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Authentication;

use Drupal\coffee_journal_api\Service\PersonalConnections;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Authentication\AuthenticationProviderFilterInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bearer authentication is valid exclusively on dedicated connector routes.
 */
final class ConnectorAuthentication implements AuthenticationProviderInterface, AuthenticationProviderFilterInterface {

  public function __construct(private readonly PersonalConnections $connections) {}

  public function applies(Request $request) {
    return str_starts_with($request->getPathInfo(), '/api/integrations/v1/')
      && $request->headers->has('Authorization');
  }

  public function authenticate(Request $request) {
    if (!preg_match('/^Bearer (cj_[0-9a-f]{64})$/Di', $request->headers->get('Authorization', ''), $match)) {
      return NULL;
    }
    $connection = $this->connections->verify($match[1]);
    if (!$connection) {
      return NULL;
    }
    $request->attributes->set('_coffee_connection', $connection);
    return $connection['account'];
  }

  public function appliesToRoutedRequest(Request $request, $authenticated) {
    $route = $request->attributes->get('_route_object');
    return in_array('coffee_connector', $route?->getOption('_auth') ?? [], TRUE);
  }

}
