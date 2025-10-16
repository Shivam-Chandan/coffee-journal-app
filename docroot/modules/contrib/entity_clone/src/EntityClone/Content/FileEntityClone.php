<?php

namespace Drupal\entity_clone\EntityClone\Content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\entity_clone\EntityCloneClonableFieldInterface;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ContentEntityCloneBase.
 */
class FileEntityClone extends ContentEntityCloneBase {

  /**
   * Drupal File Repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * Constructs a new ContentEntityCloneFormBase.
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
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   File Repository.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   Current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, string $entity_type_id, TimeInterface $time_service, AccountProxyInterface $current_user, EntityCloneClonableFieldInterface $entity_clone_clonable_field, FileRepositoryInterface $fileRepository, AccountInterface $user) {
    parent::__construct($entity_type_manager, $entity_type_id, $time_service, $current_user, $entity_clone_clonable_field);
    $this->fileRepository = $fileRepository;
    $this->user = $user;
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
      $container->get('file.repository'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function cloneEntity(EntityInterface $entity, EntityInterface $cloned_entity, array $properties = [], array &$already_cloned = []) {
    /** @var \Drupal\file\FileInterface $cloned_entity */
    // @phpstan-ignore-next-line as it is removed from D12.
    $cloned_file = $this->fileRepository->copy($cloned_entity, $cloned_entity->getFileUri(), FileSystemInterface::EXISTS_RENAME);
    if (isset($properties['take_ownership']) && $properties['take_ownership'] === 1) {
      $cloned_file->setOwnerId($this->user->id());
    }
    return parent::cloneEntity($entity, $cloned_file, $properties);
  }

}
