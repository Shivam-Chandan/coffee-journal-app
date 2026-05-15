<?php

declare(strict_types=1);

namespace Drupal\brew_privacy\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\Event\QueryEvent;
use Drupal\jsonapi\JsonApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alters JSON:API coffee_bean list queries to enforce privacy defaults.
 */
final class QueryAccessSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      JsonApiEvents::QUERY => ['onQuery', 0],
    ];
  }

  public function onQuery(QueryEvent $event): void {
    $request = $event->getRequest();
    if ($request->getMethod() !== 'GET') {
      return;
    }

    if ($request->getPathInfo() !== '/jsonapi/node/coffee_bean') {
      return;
    }

    $filters = $request->query->get('filter', []);
    $uid_filter = NULL;
    if (is_array($filters)) {
      if (isset($filters['uid'])) {
        $uid_filter = $filters['uid'];
      }
      elseif (isset($filters['uid.id'])) {
        $uid_filter = $filters['uid.id'];
      }
    }
    if ($uid_filter !== NULL && (string) $uid_filter === (string) $this->currentUser->id()) {
      return;
    }

    $query = $event->getQuery();
    $query->condition('field_is_public', 1);
  }
}
