<?php

namespace Drupal\entity_clone\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeEvent;
use Drupal\Core\Entity\EntityTypeEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to the entity type events.
 */
class EntityTypeSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a EntityTypeSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   A configuration factory instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[EntityTypeEvents::CREATE][] = ['enableCreatedEntityType'];

    return $events;
  }

  /**
   * Subscribes to the entity type create event to enable the new entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeEvent $event
   *   The entity type event.
   */
  public function enableCreatedEntityType(EntityTypeEvent $event): void {
    $clonable_entities_config = $this->configFactory->getEditable('entity_clone.cloneable_entities');
    $clonable_entities = $clonable_entities_config->get('cloneable_entities');
    $entity_type_id = $event->getEntityType()->id();
    if (!empty($clonable_entities) && in_array($entity_type_id, $clonable_entities)) {
      return;
    }
    $clonable_entities[] = $entity_type_id;
    $clonable_entities_config->set('cloneable_entities', $clonable_entities)->save();
  }

}
