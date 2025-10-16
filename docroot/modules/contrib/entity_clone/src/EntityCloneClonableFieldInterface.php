<?php

namespace Drupal\entity_clone;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * The interface for class EntityCloneClonableField.
 */
interface EntityCloneClonableFieldInterface {

  /**
   * Return whether a field can be cloned or not.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field.
   *
   * @return bool
   *   True if the entity can be cloned.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function isClonable(FieldDefinitionInterface $field_definition, FieldItemListInterface $field): bool;

}
