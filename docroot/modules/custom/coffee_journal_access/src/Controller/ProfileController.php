<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_access\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class ProfileController extends ControllerBase {

  private DateFormatterInterface $dateFormatter;
  private RequestStack $requestStack;

  public function __construct(DateFormatterInterface $dateFormatter, RequestStack $requestStack) {
    $this->dateFormatter = $dateFormatter;
    $this->requestStack  = $requestStack;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('request_stack'),
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

    $tab = $this->requestStack->getCurrentRequest()->query->get('tab', 'posts');

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

    $storage = $this->entityTypeManager()->getStorage('node');

    // Count beans and brews owned by this user.
    $bean_count = $storage->getQuery()
      ->condition('type', 'coffee_bean')
      ->condition('uid', $uid)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    $brew_count = $storage->getQuery()
      ->condition('type', 'brew_recipe')
      ->condition('uid', $uid)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    // Load tile nodes for the active tab.
    $tiles = [];
    if ($tab === 'beans') {
      $nids = $storage->getQuery()
        ->condition('type', 'coffee_bean')
        ->condition('uid', $uid)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 18)
        ->accessCheck(FALSE)
        ->execute();
      $tiles = $storage->loadMultiple($nids);
    }
    elseif ($tab === 'brews') {
      $nids = $storage->getQuery()
        ->condition('type', 'brew_recipe')
        ->condition('uid', $uid)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 18)
        ->accessCheck(FALSE)
        ->execute();
      $tiles = $storage->loadMultiple($nids);
    }
    elseif ($tab === 'saved') {
      // Load bookmarked nodes via the bookmark module (if installed).
      if (\Drupal::moduleHandler()->moduleExists('coffee_journal_bookmark')) {
        try {
          /** @var \Drupal\coffee_journal_bookmark\Repository\BookmarkRepository $repo */
          $repo = \Drupal::service('coffee_journal_bookmark.repository');
          $bookmark_nids = $repo->listForUser((int) $uid);
          // Limit to first 18; load only accessible nodes.
          $bookmark_nids = array_slice($bookmark_nids, 0, 18);
          if (!empty($bookmark_nids)) {
            $accessible = $storage->getQuery()
              ->condition('nid', $bookmark_nids, 'IN')
              ->condition('status', 1)
              ->accessCheck(TRUE)
              ->execute();
            $tiles = $storage->loadMultiple($accessible);
          }
        }
        catch (\Throwable $e) {
          // Silent fail — bookmark module may not have run schema install yet.
        }
      }
    }
    else {
      // Default "posts" tab: public brew_recipe nodes.
      $query = $storage->getQuery()
        ->condition('type', 'brew_recipe')
        ->condition('uid', $uid)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 18)
        ->accessCheck(FALSE);
      if ($user->hasField('field_is_public')) {
        // Field may not exist yet; guard against it.
      }
      if (\Drupal::service('entity_field.manager')
            ->getFieldStorageDefinitions('node')['field_is_public'] ?? FALSE) {
        $query->condition('field_is_public', 1);
      }
      $nids = $query->execute();
      $tiles = $storage->loadMultiple($nids);
    }

    // Build lightweight tile data for the template.
    $tile_data = [];
    foreach ($tiles as $node) {
      $image_url = NULL;
      $image_field = $node->bundle() === 'coffee_bean' ? 'field_coffee_bean_photo' : 'field_photo';
      if ($node->hasField($image_field) && !$node->get($image_field)->isEmpty()) {
        $file = $node->get($image_field)->entity;
        if ($file) {
          $image_url = \Drupal::service('file_url_generator')
            ->generateAbsoluteString($file->getFileUri());
        }
      }
      $tile_data[] = [
        'nid'       => $node->id(),
        'title'     => $node->label(),
        'url'       => '/node/' . $node->id(),
        'image_url' => $image_url,
        'type'      => $node->bundle(),
      ];
    }

    $user_data = [
      'id'          => $user->id(),
      'name'        => $user->getDisplayName(),
      'email'       => $user->getEmail(),
      'created'     => $user->getCreatedTime(),
      'last_login'  => $user->getLastLoginTime(),
      'picture_url' => $picture_url,
      'initial'     => mb_strtoupper(mb_substr($user->getDisplayName(), 0, 1)),
    ];

    return [
      '#theme'    => 'profile_page',
      '#user'     => $user_data,
      '#stats'    => [
        'beans'  => (int) $bean_count,
        'brews'  => (int) $brew_count,
        'hearts' => 0,
      ],
      '#tab'      => $tab,
      '#tiles'    => $tile_data,
      '#cache'    => ['max-age' => 0],
    ];
  }
}

