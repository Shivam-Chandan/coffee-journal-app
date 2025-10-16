<?php

namespace Drupal\entity_clone\EventSubscriber;

use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\entity_clone\Event\EntityCloneEvent;
use Drupal\entity_clone\Event\EntityCloneEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Entity Clone event subscriber.
 */
class EntityCloneSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * Handle translation before cloning.
   *
   * @param \Drupal\entity_clone\Event\EntityCloneEvent $event
   *   The event.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function translationPreClone(EntityCloneEvent $event):void {

    $duplicate = $event->getClonedEntity();

    $entity = $event->getEntity();
    $properties = $event->getProperties();

    if ($duplicate instanceof TranslatableInterface && $entity instanceof TranslatableInterface) {
      // Prepare translation if we need to clone it as a new entity.
      if (!$entity->isDefaultTranslation() && isset($properties['current_translation']) && $properties['current_translation']) {
        $langcode_to_keep = $entity->language()->getId();

        // Check that the translation exist.
        if (!$duplicate->hasTranslation($langcode_to_keep) || $duplicate->language()->getId() === $langcode_to_keep) {
          return;
        }

        // Extract values of translation to keep.
        $values_to_keep = $duplicate->getTranslation($langcode_to_keep)->toArray();

        // The removed translation needs to be saved or we can not change the
        // the language to that.
        $duplicate->removeTranslation($langcode_to_keep);

        // Enabling synchronization to prevent revision creation when
        // content moderation is enabled.
        if ($duplicate instanceof SynchronizableInterface) {
          $duplicate->setSyncing(TRUE);
        }
        $duplicate->save();

        // Set new langcode.
        $duplicate->set('langcode', $langcode_to_keep);

        // Copy translatable field values.
        foreach ($values_to_keep as $field_name => $field_values) {
          if ($duplicate->getFieldDefinition($field_name)->isTranslatable() && $field_name != 'default_langcode') {
            $duplicate->set($field_name, $field_values);
          }
        }

        $duplicate->save();

        foreach ($duplicate->getTranslationLanguages() as $translation_language) {
          if ($translation_language->getId() !== $langcode_to_keep) {
            $duplicate->removeTranslation($translation_language->getId());
          }
        }
      }
      elseif (isset($properties['all_translations']) && !$properties['all_translations']) {
        foreach ($duplicate->getTranslationLanguages() as $translation_language) {
          if (isset($properties[$translation_language->getId()]) && !$properties[$translation_language->getId()]) {
            $duplicate->removeTranslation($translation_language->getId());
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityCloneEvents::PRE_CLONE => ['translationPreClone', -100],
    ];
  }

}
