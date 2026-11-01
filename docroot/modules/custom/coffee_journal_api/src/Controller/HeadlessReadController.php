<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\coffee_journal_api\Service\PersonalConnections;
use Drupal\coffee_journal_api\Service\AssistantConnectionStatusInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read contracts for the headless frontend.
 *
 * Responses are deliberately uncacheable at shared proxies during the pilot.
 */
final class HeadlessReadController extends ControllerBase {

  private const PAGE_SIZE = 20;

  public function __construct(
    private readonly HeadlessPresenter $presenter,
    private readonly PersonalConnections $connections,
    private readonly ?object $likes = NULL,
    private readonly ?object $bookmarks = NULL,
    private readonly ?AssistantConnectionStatusInterface $oauthStatus = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.presenter'),
      $container->get('coffee_journal_api.connections'),
      $container->has('coffee_journal_likes.repository') ? $container->get('coffee_journal_likes.repository') : NULL,
      $container->has('coffee_journal_bookmark.repository') ? $container->get('coffee_journal_bookmark.repository') : NULL,
      $container->has('coffee_journal_oauth.connection_status') ? $container->get('coffee_journal_oauth.connection_status') : NULL,
    );
  }

  /**
   * Returns the current browser session without exposing account secrets.
   */
  public function session(): JsonResponse {
    $account = $this->currentUser();
    $user = $account->isAuthenticated()
      ? $this->entityTypeManager()->getStorage('user')->load($account->id())
      : NULL;
    return $this->presenter->response([
      'data' => [
        'authenticated' => $account->isAuthenticated(),
        'user' => $account->isAuthenticated() ? [
          'id' => (int) $account->id(),
          'name' => $account->getDisplayName(),
          'email' => $user ? (string) $user->getEmail() : '',
          'can_create' => $this->entityTypeManager()
            ->getAccessControlHandler('node')
            ->createAccess('coffee_bean', $account),
        ] : NULL,
      ],
    ]);
  }

  /**
   * Describes writable fields, options and limits for the frontend forms.
   */
  public function formOptions(): JsonResponse {
    return $this->presenter->response(['data' => $this->presenter->formOptions()]);
  }

  /**
   * Lists accessible published beans belonging to the current account.
   */
  public function beans(Request $request): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return $this->presenter->error('authentication_required', 'Sign in to view your journal.', 401);
    }
    return $this->collection($request, 'coffee_bean', FALSE);
  }

  /**
   * Lists only accessible published public brews, even for their owners.
   */
  public function feed(Request $request): JsonResponse {
    return $this->collection($request, 'brew_recipe', TRUE);
  }

  /**
   * Returns one bean with its brews.
   */
  public function bean(string $uuid): JsonResponse {
    $node = $this->presenter->loadNode($uuid, 'coffee_bean');
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'This coffee is unavailable.', 404);
    }
    $data = $this->presenter->beanDetail($node, $this->currentUser());
    $data['brews'] = [];
    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'brew_recipe')
      ->condition('status', 1)
      ->condition('field_coffee_bean_ref', $node->id())
      ->sort('nid', 'DESC')
      ->range(0, 50)
      ->execute();
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($ids) as $brew) {
      if ($brew instanceof NodeInterface && $brew->access('view', $this->currentUser())) {
        $data['brews'][] = $this->presenter->brewCard($brew, $this->currentUser());
      }
    }
    return $this->presenter->response(['data' => $data]);
  }

  /**
   * Returns one brew.
   */
  public function brew(string $uuid): JsonResponse {
    $node = $this->presenter->loadNode($uuid, 'brew_recipe');
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'This brew is unavailable.', 404);
    }
    return $this->presenter->response([
      'data' => $this->presenter->brewDetail($node, $this->currentUser()),
    ]);
  }

  /**
   * Lists the account's beans as lightweight choices for the brew form.
   */
  public function beanChoices(): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return $this->presenter->error('authentication_required', 'Sign in to view your coffees.', 401);
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'coffee_bean')
      ->condition('status', 1)
      ->condition('uid', $this->currentUser()->id())
      ->sort('nid', 'DESC')
      ->range(0, 100)
      ->execute();
    $data = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($node instanceof NodeInterface && $node->access('view', $this->currentUser())) {
        $data[] = [
          'id' => $node->uuid(),
          'title' => (string) $node->label(),
          'brand' => (string) $this->presenter->value($node, 'field_brand_roaster', $this->currentUser(), ''),
        ];
      }
    }
    return $this->presenter->response(['data' => $data]);
  }

  /**
   * Returns the signed-in account's profile and counts.
   */
  public function profile(): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return $this->presenter->error('authentication_required', 'Sign in to view your profile.', 401);
    }
    $user = $this->entityTypeManager()->getStorage('user')->load($account->id());
    if (!$user) {
      return $this->presenter->error('not_found', 'Account unavailable.', 404);
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $count = static fn (string $bundle): int => (int) $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->condition('uid', $account->id())
      ->count()
      ->execute();
    $likeCount = 0;
    if ($this->likes) {
      $likeCount = count($this->likes->listForUser((int) $account->id()));
    }
    return $this->presenter->response([
      'data' => [
        'id' => (int) $account->id(),
        'name' => $account->getDisplayName(),
        'email' => (string) $user->getEmail(),
        'initial' => mb_strtoupper(mb_substr((string) $account->getDisplayName(), 0, 1)),
        'member_since' => (int) $user->getCreatedTime(),
        'chatgpt_app_url' => $this->connections->chatgptAppUrl($account),
        'assistant_connections' => $this->oauthStatus?->status($account),
        'stats' => [
          'beans' => $count('coffee_bean'),
          'brews' => $count('brew_recipe'),
          'likes' => $likeCount,
        ],
      ],
    ]);
  }

  /**
   * Lists the account's own brews for the profile posts tab.
   */
  public function myBrews(Request $request): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return $this->presenter->error('authentication_required', 'Sign in to view your brews.', 401);
    }
    return $this->collection($request, 'brew_recipe', FALSE);
  }

  /**
   * Lists currently accessible bookmarked content.
   */
  public function saved(): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return $this->presenter->error('authentication_required', 'Sign in to view saved items.', 401);
    }
    if (!$this->bookmarks) {
      return $this->presenter->response(['data' => [], 'meta' => ['next' => NULL]]);
    }
    $ids = array_slice($this->bookmarks->listForUser((int) $this->currentUser()->id()), 0, self::PAGE_SIZE);
    $data = [];
    if ($ids) {
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($ids);
      foreach ($ids as $id) {
        $node = $nodes[$id] ?? NULL;
        // Revoked visibility must drop the item from saved lists immediately.
        if (!$node instanceof NodeInterface || !$node->access('view', $this->currentUser())) {
          continue;
        }
        $data[] = $node->bundle() === 'coffee_bean'
          ? ['kind' => 'bean'] + $this->presenter->beanCard($node, $this->currentUser())
          : ['kind' => 'brew'] + $this->presenter->brewCard($node, $this->currentUser());
      }
    }
    return $this->presenter->response(['data' => $data, 'meta' => ['next' => NULL]]);
  }

  /**
   * Returns a bounded page with an exclusive descending node-ID cursor.
   */
  private function collection(Request $request, string $bundle, bool $public): JsonResponse {
    $before = $request->query->all()['before'] ?? NULL;
    if ($before !== NULL && (!is_string($before) || !ctype_digit($before) || (int) $before < 1 || (string) (int) $before !== $before)) {
      return $this->presenter->error('invalid_cursor', 'Invalid pagination cursor.', 400);
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->sort('nid', 'DESC')
      ->range(0, self::PAGE_SIZE + 1);
    if ($public) {
      $query->condition('field_is_public', 1);
    }
    else {
      // Public entries belonging to other users never enter personal lists.
      $query->condition('uid', $this->currentUser()->id());
    }
    if ($before !== NULL) {
      $query->condition('nid', (int) $before, '<');
    }
    $nodes = $storage->loadMultiple($query->execute());
    $more = count($nodes) > self::PAGE_SIZE;
    $nodes = array_slice($nodes, 0, self::PAGE_SIZE, TRUE);
    $data = [];
    foreach ($nodes as $node) {
      // Keep entity checks even when collection grants already filtered IDs.
      if (!$node instanceof NodeInterface || !$node->access('view', $this->currentUser())) {
        continue;
      }
      if ($public && (!$node->get('field_is_public')->access('view', $this->currentUser()) || !$node->get('field_is_public')->value)) {
        continue;
      }
      $data[] = $bundle === 'coffee_bean'
        ? $this->presenter->beanCard($node, $this->currentUser())
        : $this->presenter->brewCard($node, $this->currentUser());
    }
    $last = end($nodes);
    return $this->presenter->response([
      'data' => $data,
      'meta' => ['next' => $more && $last ? (int) $last->id() : NULL],
    ]);
  }

}
