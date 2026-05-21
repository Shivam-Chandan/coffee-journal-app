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
    $uid       = (int) $this->currentUser()->id();
    $nid       = (int) $node->id();
    $bookmarked = $this->repo->toggle($uid, $nid);

    return new JsonResponse([
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

    return new JsonResponse(['nids' => $nids]);
  }

}
