<?php

declare(strict_types=1);

namespace Drupal\brew_privacy\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stub subscriber kept for compatibility — access control is handled via
 * hook_node_access_records() and hook_node_grants() in brew_privacy.module.
 *
 * The original implementation referenced Drupal\jsonapi\Event\QueryEvent and
 * Drupal\jsonapi\JsonApiEvents which do not exist in Drupal 11's JSON:API
 * module. JSON:API collection access for coffee_bean nodes is correctly
 * enforced by the node grant system already implemented in brew_privacy.module.
 * If per-field JSON:API filter scoping is needed, implement
 * hook_jsonapi_node_filter_access() in brew_privacy.module instead.
 */
final class QueryAccessSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    // No events subscribed — access control handled by node grants.
    return [];
  }

}
