<?php

namespace Drupal\coffee_journal_bookmark\Controller;

use Drupal\coffee_journal_bookmark\Repository\BookmarkRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * REST endpoints for bookmarking nodes.
 */
class BookmarkController extends ControllerBase {

  public function __construct(protected BookmarkRepository $repo) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_bookmark.repository'));
  }

  /**
   * POST /api/bookmarks/{node} — toggle bookmark for the current user.
   */
  public function toggle(NodeInterface $node): JsonResponse {
    if (!$this->canEngage($node)) {
      return $this->privateResponse(['error' => 'Content is unavailable.'], 403);
    }
    $uid       = (int) $this->currentUser()->id();
    $nid       = (int) $node->id();
    $bookmarked = $this->repo->toggle($uid, $nid);

    return $this->privateResponse([
      'bookmarked' => $bookmarked,
      'nid'        => $nid,
    ]);
  }

  /**
   * GET /api/bookmarks — list NIDs bookmarked by the current user.
   */
  public function list(): JsonResponse {
    $uid  = (int) $this->currentUser()->id();
    $nids = $this->repo->listForUser($uid);

    $visible = [];
    foreach (array_chunk($nids, 100) as $chunk) {
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($chunk);
      foreach ($chunk as $nid) {
        if (isset($nodes[$nid]) && $this->canEngage($nodes[$nid])) {
          $visible[] = (int) $nid;
        }
      }
    }
    return $this->privateResponse(['nids' => $visible]);
  }

  private function canEngage(NodeInterface $node): bool {
    return in_array($node->bundle(), ['coffee_bean', 'brew_recipe'], TRUE) &&
      $node->access('view', $this->currentUser());
  }

  private function privateResponse(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
