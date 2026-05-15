<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns the current authenticated user's state as JSON.
 *
 * Endpoint: GET /api/user/me
 */
final class UserStateController extends ControllerBase {

  public function __construct() {}

  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Returns the current user's profile data as a JSON response.
   */
  public function me(): JsonResponse {
    $account = $this->currentUser;

    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager()
      ->getStorage('user')
      ->load($account->id());

    if (!$user instanceof UserInterface) {
      return new JsonResponse(['error' => 'User not found.'], 404);
    }

    $picture_url = NULL;
    if (!$user->get('field_profile_picture')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $user->get('field_profile_picture')->entity;
      if ($file) {
        $picture_url = \Drupal::service('file_url_generator')
          ->generateAbsoluteString($file->getFileUri());
      }
    }

    $data = [
      'id'              => (int) $user->id(),
      'name'            => $user->getDisplayName(),
      'email'           => $user->getEmail(),
      'roles'           => array_values(array_diff($user->getRoles(), ['authenticated'])),
      'created'         => (int) $user->getCreatedTime(),
      'last_login'      => (int) $user->getLastLoginTime(),
      'picture_url'     => $picture_url,
      'bio'             => $user->get('field_bio')->isEmpty()
                             ? NULL
                             : (string) $user->get('field_bio')->value,
      'experience_level' => $user->get('field_experience_level')->isEmpty()
                              ? NULL
                              : (string) $user->get('field_experience_level')->value,
    ];

    return new JsonResponse($data);
  }

}
