<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_likes\Repository\LikeRepository;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the global feed page.
 */
final class FeedController extends ControllerBase {

  public function __construct(private readonly LikeRepository $likeRepo) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_likes.repository'),
    );
  }

  /**
   * Renders the global community feed page.
   */
  public function globalFeed(): array {
    $nodeStorage = $this->entityTypeManager()->getStorage('node');
    $userStorage = $this->entityTypeManager()->getStorage('user');
    $fileUrlGen  = \Drupal::service('file_url_generator');
    $dateFormatter = \Drupal::service('date.formatter');

    $nids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'brew_recipe')
      ->condition('field_is_public', 1)
      ->sort('created', 'DESC')
      ->range(0, 20)
      ->execute();

    $nodes = $nodeStorage->loadMultiple(array_values($nids));
    $nid_list = array_map('intval', array_keys($nodes));

    // Pre-load like counts for all cards in one query.
    $like_counts = $this->likeRepo->countForNodes($nid_list);

    // Pre-load all authors.
    $uids = array_unique(array_map(fn($n) => (int) $n->getOwnerId(), $nodes));
    $users = $userStorage->loadMultiple($uids);

    $cards = [];
    foreach ($nodes as $node) {
      $author = $users[$node->getOwnerId()] ?? NULL;
      $author_name    = $author ? $author->getDisplayName() : 'Unknown';
      $author_initial = mb_strtoupper(mb_substr($author_name, 0, 1));
      $author_picture = NULL;
      if ($author && $author->hasField('field_profile_picture')
          && !$author->get('field_profile_picture')->isEmpty()) {
        $file = $author->get('field_profile_picture')->entity;
        if ($file) {
          $author_picture = $fileUrlGen->generateAbsoluteString($file->getFileUri());
        }
      }

      $image_url = NULL;
      if ($node->hasField('field_photo') && !$node->get('field_photo')->isEmpty()) {
        $file = $node->get('field_photo')->entity;
        if ($file) {
          $image_url = $fileUrlGen->generateAbsoluteString($file->getFileUri());
        }
      }

      $coffee_weight = $node->hasField('field_coffee_weight') ? (float) $node->field_coffee_weight->value : 0;
      $water_weight  = $node->hasField('field_water_weight')  ? (float) $node->field_water_weight->value  : 0;
      $ratio = ($coffee_weight > 0 && $water_weight > 0)
        ? number_format($water_weight / $coffee_weight, 1)
        : NULL;

      // Related bean name.
      $bean_name = NULL;
      if ($node->hasField('field_coffee_bean_ref') && !$node->get('field_coffee_bean_ref')->isEmpty()) {
        $bean = $node->get('field_coffee_bean_ref')->entity;
        if ($bean) {
          $bean_name = $bean->label();
        }
      }

      $share_caption = NULL;
      if ($node->hasField('field_share_caption') && !$node->get('field_share_caption')->isEmpty()) {
        $share_caption = $node->get('field_share_caption')->value;
      }

      $cards[] = [
        'nid'            => $node->id(),
        'title'          => $node->label(),
        'url'            => '/node/' . $node->id(),
        'method'         => $node->hasField('field_brew_method') ? $node->field_brew_method->value : NULL,
        'ratio'          => $ratio,
        'bean_name'      => $bean_name,
        'image_url'      => $image_url,
        'share_caption'  => $share_caption,
        'like_count'     => $like_counts[(int) $node->id()] ?? 0,
        'author_name'    => $author_name,
        'author_initial' => $author_initial,
        'author_picture' => $author_picture,
        'age'            => $dateFormatter->formatTimeDiffSince($node->getCreatedTime()),
      ];
    }

    return [
      '#theme'  => 'feed_page',
      '#cards'  => $cards,
      '#offset' => count($cards),
      '#cache'  => [
        'contexts' => ['user', 'user.permissions'],
        'tags'     => ['node_list:brew_recipe'],
        'max-age'  => 300,
      ],
      '#attached' => [
        'library' => ['coffee_journal/app'],
        'drupalSettings' => [
          'coffeeJournal' => [
            'feedApiEndpoint' => '/jsonapi/node/brew_recipe',
            'feedOffset'      => count($cards),
          ],
        ],
      ],
    ];
  }

}
