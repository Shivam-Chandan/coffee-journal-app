<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_access\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProfileController extends ControllerBase {

  private DateFormatterInterface $dateFormatter;

  public function __construct(DateFormatterInterface $dateFormatter) {
    $this->dateFormatter = $dateFormatter;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
    );
  }

  public function profile(): array {
    // currentUser is inherited from ControllerBase and automatically available
    $uid = $this->currentUser()->id();
    
    if (!$uid) {
      return [
        '#markup' => $this->t('You must be logged in to view this page.'),
      ];
    }

    // Load the full User entity to access getLastLoginTime().
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager()
      ->getStorage('user')
      ->load($uid);

    if (!$user instanceof UserInterface) {
      return [
        '#markup' => $this->t('User not found.'),
      ];
    }

    // Get profile picture URL if available.
    $picture_url = NULL;
    if ($user->hasField('field_profile_picture') && !$user->get('field_profile_picture')->isEmpty()) {
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $user->get('field_profile_picture')->entity;
      if ($file) {
        $picture_url = \Drupal::service('file_url_generator')
          ->generateAbsoluteString($file->getFileUri());
      }
    }

    // Extract profile field data.
    $user_data = [
      'id'               => $user->id(),
      'name'             => $user->getDisplayName(),
      'email'            => $user->getEmail(),
      'created'          => $user->getCreatedTime(),
      'last_login'       => $user->getLastLoginTime(),
      'picture_url'      => $picture_url,
      'bio'              => $user->hasField('field_bio') && !$user->get('field_bio')->isEmpty()
                              ? (string) $user->get('field_bio')->value
                              : NULL,
      'experience_level' => $user->hasField('field_experience_level') && !$user->get('field_experience_level')->isEmpty()
                              ? (string) $user->get('field_experience_level')->value
                              : NULL,
      'roles'            => array_diff($user->getRoles(), ['authenticated']),
    ];

    return [
      '#theme'      => 'profile_page',
      '#user'       => $user_data,
      '#attached'   => [
        'library' => ['coffee_journal_access/profile_styles'],
      ],
    ];
  }
}

