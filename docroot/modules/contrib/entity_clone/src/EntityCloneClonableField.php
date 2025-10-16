<?php

namespace Drupal\entity_clone;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\field\FieldConfigInterface;

/**
 * Manage entity clone clonable field.
 */
class EntityCloneClonableField implements EntityCloneClonableFieldInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * EntityCloneClonableField constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public function isClonable(FieldDefinitionInterface $field_definition, FieldItemListInterface $field): bool {
    if ($field_definition instanceof FieldConfigInterface
      && $field instanceof EntityReferenceFieldItemListInterface
      && $field->count() > 0) {
      $entity_storage = $this->entityTypeManager->getStorage($field->getSetting('target_type'));
      return $entity_storage instanceof ContentEntityStorageInterface;
    }
    return FALSE;
  }

}
