<?php

namespace Drupal\coffee_journal_likes\Controller;

use Drupal\coffee_journal_likes\Repository\LikeRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST endpoints for liking nodes.
 */
class LikeController extends ControllerBase {

  public function __construct(protected LikeRepository $repo) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_likes.repository'));
  }

  /**
   * POST /api/likes/{node} — toggle like for the current user.
   */
  public function toggle(NodeInterface $node): JsonResponse {
    $uid   = (int) $this->currentUser()->id();
    $nid   = (int) $node->id();
    $liked = $this->repo->toggle($uid, $nid);

    return new JsonResponse([
      'liked' => $liked,
      'nid'   => $nid,
    ]);
  }

  /**
   * GET /api/likes — list NIDs liked by the current user.
   */
  public function listMine(): JsonResponse {
    $uid  = (int) $this->currentUser()->id();
    $nids = $this->repo->listForUser($uid);

    return new JsonResponse(['nids' => $nids]);
  }

  /**
   * GET /api/likes/counts?nids=1,2,3 — public like counts for given node IDs.
   */
  public function counts(Request $request): JsonResponse {
    $raw  = $request->query->get('nids', '');
    $nids = array_filter(array_map('intval', explode(',', (string) $raw)));

    if (empty($nids)) {
      return new JsonResponse(['counts' => (object) []]);
    }

    $counts = $this->repo->countForNodes($nids);
    return new JsonResponse(['counts' => $counts]);
  }

}
