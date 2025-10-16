<?php

namespace Drupal\entity_clone_extras;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides dynamic permissions of the entity_clone module.
 */
class EntityCloneExtrasPermissions implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The string translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $translationManager;

  /**
   * Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new EntityCloneExtrasPermissions instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   *   The string translation manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module handler.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager, TranslationManager $string_translation, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_manager;
    $this->translationManager = $string_translation;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('string_translation'),
      $container->get('module_handler')
    );
  }

  /**
   * Returns an array of entity_clone_extras permissions.
   *
   * @return array
   *   The permission list.
   */
  public function permissions() {
    $permissions = [];
    // Fetch all available content types.
    $content_types = $this->entityTypeManager->getStorage('node_type')
      ->loadMultiple();
    foreach ($content_types as $key => $type) {
      // Add a permission to clone each content type.
      $permissions['clone ' . $key . ' node entities'] = $this
        ->translationManager
        ->translate('Clone all <em>@label</em> node entities', [
          '@label' => $type->label(),
        ]);
    }

    // Support media entities when media module is installed.
    if ($this->moduleHandler->moduleExists('media')) {
      // Fetch all available media types.
      $media_types = $this->entityTypeManager->getStorage('media_type')
        ->loadMultiple();
      foreach ($media_types as $key => $type) {
        // Add a permission to clone each media type.
        $permissions['clone ' . $key . ' media entities'] = $this
          ->translationManager
          ->translate('Clone all <em>@label</em> media entities', [
            '@label' => $type->label(),
          ]);
      }
    }

    return $permissions;
  }

}
