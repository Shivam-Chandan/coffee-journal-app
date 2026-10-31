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
    if (!$this->canEngage($node)) {
      return $this->privateResponse(['error' => 'Content is unavailable.'], 403);
    }
    $uid   = (int) $this->currentUser()->id();
    $nid   = (int) $node->id();
    $liked = $this->repo->toggle($uid, $nid);

    return $this->privateResponse([
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

    return $this->privateResponse(['nids' => $this->visibleIds($nids)]);
  }

  /**
   * GET /api/likes/counts?nids=1,2,3 — public like counts for given node IDs.
   */
  public function counts(Request $request): JsonResponse {
    $raw  = $request->query->get('nids', '');
    if (!is_string($raw) || strlen($raw) > 1200) {
      return $this->privateResponse(['error' => 'Provide at most 100 positive node IDs.'], 400);
    }
    $parts = $raw === '' ? [] : explode(',', $raw);
    if (count($parts) > 100 || array_filter($parts, static fn (string $id): bool => !preg_match('/^[1-9][0-9]{0,9}$/D', $id))) {
      return $this->privateResponse(['error' => 'Provide at most 100 positive node IDs.'], 400);
    }
    $nids = $this->visibleIds(array_values(array_unique(array_map('intval', $parts))));

    if (empty($nids)) {
      return $this->privateResponse(['counts' => (object) []]);
    }

    $counts = $this->repo->countForNodes($nids);
    return $this->privateResponse(['counts' => (object) $counts]);
  }

  private function canEngage(NodeInterface $node): bool {
    return in_array($node->bundle(), ['coffee_bean', 'brew_recipe'], TRUE) &&
      $node->access('view', $this->currentUser());
  }

  private function visibleIds(array $nids): array {
    $visible = [];
    foreach (array_chunk($nids, 100) as $chunk) {
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($chunk);
      foreach ($chunk as $nid) {
        if (isset($nodes[$nid]) && $this->canEngage($nodes[$nid])) {
          $visible[] = (int) $nid;
        }
      }
    }
    return $visible;
  }

  private function privateResponse(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
