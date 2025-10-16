<?php

namespace Drupal\entity_clone\EntityClone\Config;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\entity_clone\EntityCloneClonableFieldInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Content Entity Clone Base.
 */
class ConfigWithFieldEntityClone extends ConfigEntityCloneBase {

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a ConfigWithFieldEntityClone instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\Component\Datetime\TimeInterface $time_service
   *   A service for obtaining the system's time.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\entity_clone\EntityCloneClonableFieldInterface $entity_clone_clonable_field
   *   The entity clone clonable field service.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity Field Manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, string $entity_type_id, TimeInterface $time_service, AccountProxyInterface $current_user, EntityCloneClonableFieldInterface $entity_clone_clonable_field, EntityDisplayRepositoryInterface $entity_display_repository, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($entity_type_manager, $entity_type_id, $time_service, $current_user, $entity_clone_clonable_field);
    $this->entityDisplayRepository = $entity_display_repository;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $container->get('entity_type.manager'),
      $entity_type->id(),
      $container->get('datetime.time'),
      $container->get('current_user'),
      $container->get('entity_clone.clonable_field'),
      $container->get('entity_display.repository'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function cloneEntity(EntityInterface $entity, EntityInterface $cloned_entity, array $properties = []) {
    $cloned_entity = parent::cloneEntity($entity, $cloned_entity, $properties);
    $bundle_of = $cloned_entity->getEntityType()->getBundleOf();
    if ($bundle_of) {
      $this->cloneFields($entity->id(), $cloned_entity->id(), $bundle_of);
    }

    $view_displays = $this->entityDisplayRepository->getFormModes($bundle_of);
    $view_displays = array_merge($view_displays, ['default' => 'default']);
    if (!empty($view_displays)) {
      $this->cloneDisplays('form', $entity->id(), $cloned_entity->id(), $view_displays, $bundle_of);
    }

    $view_displays = $this->entityDisplayRepository->getViewModes($bundle_of);
    $view_displays = array_merge($view_displays, ['default' => 'default']);
    if (!empty($view_displays)) {
      $this->cloneDisplays('view', $entity->id(), $cloned_entity->id(), $view_displays, $bundle_of);
    }

    return $cloned_entity;
  }

  /**
   * Clone all fields. Each field re-use existing field storage.
   *
   * @param string $entity_id
   *   The base entity ID.
   * @param string $cloned_entity_id
   *   The cloned entity ID.
   * @param string $bundle_of
   *   The bundle of the cloned entity.
   */
  protected function cloneFields($entity_id, $cloned_entity_id, $bundle_of) {
    $fields = $this->entityFieldManager->getFieldDefinitions($bundle_of, $entity_id);
    foreach ($fields as $field_definition) {
      if ($field_definition instanceof FieldConfigInterface) {
        $field_storage = $this->entityTypeManager->getStorage($field_definition->getEntityTypeId());
        if ($field_storage->load($bundle_of . '.' . $cloned_entity_id . '.' . $field_definition->getName())) {
          continue;
        }
        if ($this->entityTypeManager->hasHandler($this->entityTypeManager->getDefinition($field_definition->getEntityTypeId())
          ->id(), 'entity_clone')
        ) {
          /** @var \Drupal\entity_clone\EntityClone\EntityCloneInterface $field_config_clone_handler */
          $field_config_clone_handler = $this->entityTypeManager->getHandler($this->entityTypeManager->getDefinition($field_definition->getEntityTypeId())
            ->id(), 'entity_clone');
          $field_config_properties = [
            'id' => $field_definition->getName(),
            'label' => $field_definition->label(),
            'skip_storage' => TRUE,
          ];
          $cloned_field_definition = $field_definition->createDuplicate();
          $cloned_field_definition->set('bundle', $cloned_entity_id);
          $field_config_clone_handler->cloneEntity($field_definition, $cloned_field_definition, $field_config_properties);
        }
      }
    }
  }

  /**
   * Clone all fields. Each field re-use existing field storage.
   *
   * @param string $type
   *   The type of display (view or form).
   * @param string $entity_id
   *   The base entity ID.
   * @param string $cloned_entity_id
   *   The cloned entity ID.
   * @param array $view_displays
   *   All view available display for this type.
   * @param string $bundle_of
   *   The bundle of the cloned entity.
   */
  protected function cloneDisplays($type, $entity_id, $cloned_entity_id, array $view_displays, $bundle_of) {
    foreach ($view_displays as $view_display_id => $view_display) {
      /** @var \Drupal\Core\Entity\Display\EntityDisplayInterface $display */
      $display = $this->entityTypeManager->getStorage('entity_' . $type . '_display')->load($bundle_of . '.' . $entity_id . '.' . $view_display_id);
      if ($display) {
        /** @var \Drupal\entity_clone\EntityClone\EntityCloneInterface $view_display_clone_handler */
        $view_display_clone_handler = $this->entityTypeManager->getHandler($this->entityTypeManager->getDefinition($display->getEntityTypeId())
          ->id(), 'entity_clone');
        $view_display_properties = [
          'id' => $bundle_of . '.' . $cloned_entity_id . '.' . $view_display_id,
        ];
        $cloned_view_display = $display->createDuplicate();
        $cloned_view_display->set('bundle', $cloned_entity_id);
        $view_display_clone_handler->cloneEntity($display, $cloned_view_display, $view_display_properties);
      }
    }
  }

}
