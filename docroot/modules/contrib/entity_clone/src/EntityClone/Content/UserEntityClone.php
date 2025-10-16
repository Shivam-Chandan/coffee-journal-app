<?php

namespace Drupal\entity_clone\EntityClone\Content;

use Drupal\Core\Entity\EntityInterface;

/**
 * Class ContentEntityCloneBase.
 */
class UserEntityClone extends ContentEntityCloneBase {

  /**
   * {@inheritdoc}
   */
  public function cloneEntity(EntityInterface $entity, EntityInterface $cloned_entity, array $properties = [], array &$already_cloned = []) {
    $uniqueUsername = $this->getUniqueUserName($cloned_entity->getAccountName() . '_cloned');
    /** @var \Drupal\user\UserInterface $cloned_entity */
    $cloned_entity->set('name', $uniqueUsername);
    return parent::cloneEntity($entity, $cloned_entity, $properties);
  }

  /**
   * Returns a unique username.
   *
   * @param string $username
   *   The Username.
   *
   * @return string
   *   The suffixed unique username.
   */
  protected function getUniqueUserName($username) {
    $storage = $this->entityTypeManager->getStorage('user');
    $original = $username;

    $suffix = 0;
    while ($matches = $storage->loadByProperties(['name' => $username])) {
      $username = $original . '_' . $suffix++;
    }

    return $username;
  }

}
