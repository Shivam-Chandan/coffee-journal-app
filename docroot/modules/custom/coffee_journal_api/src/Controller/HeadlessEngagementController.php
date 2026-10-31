<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Explicit desired-state engagement contracts.
 *
 * PUT/DELETE are safe to retry, unlike the legacy toggle endpoints which flip
 * state on every call. The legacy routes stay in place for the Drupal UI.
 */
final class HeadlessEngagementController extends ControllerBase {

  public function __construct(
    private readonly HeadlessPresenter $presenter,
    private readonly ?object $likes = NULL,
    private readonly ?object $bookmarks = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.presenter'),
      $container->has('coffee_journal_likes.repository') ? $container->get('coffee_journal_likes.repository') : NULL,
      $container->has('coffee_journal_bookmark.repository') ? $container->get('coffee_journal_bookmark.repository') : NULL,
    );
  }

  /**
   * Records a like for the current account.
   */
  public function like(string $uuid): JsonResponse {
    return $this->setState($uuid, 'like', TRUE);
  }

  /**
   * Removes a like for the current account.
   */
  public function unlike(string $uuid): JsonResponse {
    return $this->setState($uuid, 'like', FALSE);
  }

  /**
   * Saves content for the current account.
   */
  public function bookmark(string $uuid): JsonResponse {
    return $this->setState($uuid, 'bookmark', TRUE);
  }

  /**
   * Removes saved content for the current account.
   */
  public function unbookmark(string $uuid): JsonResponse {
    return $this->setState($uuid, 'bookmark', FALSE);
  }

  /**
   * Applies the requested state idempotently.
   */
  private function setState(string $uuid, string $kind, bool $desired): JsonResponse {
    $repository = $kind === 'like' ? $this->likes : $this->bookmarks;
    if (!$repository) {
      return $this->presenter->error('unavailable', 'This action is unavailable.', 503);
    }
    $node = $this->presenter->loadNode($uuid, 'brew_recipe')
      ?? $this->presenter->loadNode($uuid, 'coffee_bean');
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'Content is unavailable.', 404);
    }
    $uid = (int) $this->currentUser()->id();
    $nid = (int) $node->id();
    $current = $kind === 'like'
      ? $repository->isLiked($uid, $nid)
      : $repository->isBookmarked($uid, $nid);
    // Only touch storage when the stored state differs from the request.
    if ($current !== $desired) {
      $repository->toggle($uid, $nid);
    }
    return $this->presenter->response([
      'data' => [
        'id' => $node->uuid(),
        'engagement' => $this->presenter->engagement($node, $this->currentUser()),
      ],
    ]);
  }

}
