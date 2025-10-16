<?php

namespace Drupal\entity_clone\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity Clone Contextual Links Derivative helper.
 *
 * @package Drupal\entity_clone\Plugin\Derivative
 */
class EntityCloneContextualLinks extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $translationManager;

  /**
   * Constructs a new EntityCloneContextualLinks.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationManager $stringTranslation
   *   The translation manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, TranslationManager $stringTranslation) {
    $this->entityTypeManager = $entityTypeManager;
    $this->translationManager = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    // Create contextual links for entity clone.
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      $this->derivatives["$entity_type_id.clone_tab"] = [
        'route_name' => "entity.$entity_type_id.clone_form",
        'title' => $this->translationManager->translate('Clone'),
        'group' => $entity_type_id,
      ];
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
