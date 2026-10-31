<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared transport authentication guard and connection request budget.
 */
final class ConnectorAccess {

  public function __construct(
    private readonly HeadlessPresenter $presenter,
    private readonly AccountProxyInterface $account,
    private readonly FloodInterface $flood,
  ) {}

  public function authorize(Request $request): ?JsonResponse {
    $connection = $request->attributes->get('_coffee_connection');
    if (!$connection || (int) $connection['account']->id() !== (int) $this->account->id()) {
      $response = $this->presenter->error('authentication_required', 'A valid personal connector bearer credential is required.', 401);
      $response->headers->set('WWW-Authenticate', 'Bearer');
      return $response;
    }
    if (!$this->flood->isAllowed('coffee_connector', 60, 60, $connection['id'])) {
      $response = $this->presenter->error('rate_limited', 'Try again in a minute.', 429);
      $response->headers->set('Retry-After', '60');
      return $response;
    }
    $this->flood->register('coffee_connector', 60, $connection['id']);
    return NULL;
  }

}
