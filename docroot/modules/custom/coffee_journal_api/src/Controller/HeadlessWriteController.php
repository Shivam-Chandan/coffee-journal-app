<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\coffee_journal_api\Service\JournalInput;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Create, update and delete contracts for the headless frontend.
 *
 * Drupal stays authoritative: every request re-checks entity and field access,
 * runs entity validation, and rejects stale or replayed submissions.
 */
final class HeadlessWriteController extends ControllerBase {

  public function __construct(
    private readonly HeadlessPresenter $presenter,
    private readonly JournalInput $input,
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.presenter'),
      $container->get('coffee_journal_api.journal_input'),
      $container->get('keyvalue.expirable'),
    );
  }

  /**
   * Creates a bean owned by the authenticated account.
   */
  public function createBean(Request $request): JsonResponse {
    return $this->createNode($request, 'coffee_bean');
  }

  /**
   * Creates a brew owned by the authenticated account.
   */
  public function createBrew(Request $request): JsonResponse {
    return $this->createNode($request, 'brew_recipe');
  }

  /**
   * Updates an existing bean the account may edit.
   */
  public function updateBean(string $uuid, Request $request): JsonResponse {
    return $this->updateNode($uuid, 'coffee_bean', $request);
  }

  /**
   * Updates an existing brew the account may edit.
   */
  public function updateBrew(string $uuid, Request $request): JsonResponse {
    return $this->updateNode($uuid, 'brew_recipe', $request);
  }

  /**
   * Deletes a bean, refusing while brews still reference it.
   */
  public function deleteBean(string $uuid): JsonResponse {
    $node = $this->presenter->loadNode($uuid, 'coffee_bean');
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'This coffee is unavailable.', 404);
    }
    if (!$node->access('delete', $this->currentUser())) {
      return $this->presenter->error('forbidden', 'You cannot delete this coffee.', 403);
    }
    // Never silently destroy brew history that belongs to the same account.
    $referencing = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'brew_recipe')
      ->condition('field_coffee_bean_ref', $node->id())
      ->count()
      ->execute();
    if ($referencing > 0) {
      return $this->presenter->error(
        'conflict',
        sprintf('Delete or detach %d brew(s) using this coffee first.', $referencing),
        409
      );
    }
    $node->delete();
    return $this->presenter->response(['data' => ['deleted' => TRUE]]);
  }

  /**
   * Deletes a brew the account may delete.
   */
  public function deleteBrew(string $uuid): JsonResponse {
    $node = $this->presenter->loadNode($uuid, 'brew_recipe');
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'This brew is unavailable.', 404);
    }
    if (!$node->access('delete', $this->currentUser())) {
      return $this->presenter->error('forbidden', 'You cannot delete this brew.', 403);
    }
    $node->delete();
    return $this->presenter->response(['data' => ['deleted' => TRUE]]);
  }

  /**
   * Sets explicit brew visibility instead of toggling blindly.
   */
  public function setVisibility(string $uuid, Request $request): JsonResponse {
    $node = $this->presenter->loadNode($uuid, 'brew_recipe');
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'This brew is unavailable.', 404);
    }
    if (!$node->access('update', $this->currentUser())) {
      return $this->presenter->error('forbidden', 'You cannot share this brew.', 403);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->presenter->error('invalid_request', 'Expected a JSON object.', 400);
    }
    if (!array_key_exists('is_public', $payload) || !is_bool($payload['is_public'])) {
      return $this->presenter->error('validation_failed', 'Provide the desired visibility.', 422, [
        'is_public' => 'Expected true or false.',
      ]);
    }
    $this->assign($node, 'field_is_public', $payload['is_public']);
    if (array_key_exists('caption', $payload)) {
      if (!is_string($payload['caption'])) {
        return $this->presenter->error('validation_failed', 'Caption must be text.', 422, [
          'caption' => 'Expected text.',
        ]);
      }
      $this->assign($node, 'field_share_caption', trim($payload['caption']));
    }
    $violations = $this->violations($node);
    if ($violations) {
      return $this->presenter->error('validation_failed', 'Please review this brew.', 422, $violations);
    }
    $node->setNewRevision(FALSE);
    $node->save();
    return $this->presenter->response([
      'data' => $this->presenter->brewDetail($node, $this->currentUser()),
    ]);
  }

  /**
   * Creates a node after validating ownership-safe input.
   */
  private function createNode(Request $request, string $bundle): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return $this->presenter->error('authentication_required', 'Sign in to save your entry.', 401);
    }
    if (!$this->entityTypeManager()->getAccessControlHandler('node')
      ->createAccess($bundle, $account)) {
      return $this->presenter->error('forbidden', 'You cannot create this entry.', 403);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->presenter->error('invalid_request', 'Expected a JSON object.', 400);
    }
    // An account-scoped key makes an interrupted save safe to retry.
    $key = $request->headers->get('Idempotency-Key');
    if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $key)) {
      return $this->presenter->error('invalid_request', 'A valid Idempotency-Key header is required.', 400);
    }
    $lock = 'coffee_journal_api:idempotency:' . $account->id() . ':' . hash('sha256', $key);
    $existing = $this->keyValueExpirable()->get($lock);
    if (is_string($existing)) {
      $node = $this->presenter->loadNode($existing, $bundle);
      if ($node && $node->access('view', $account)) {
        return $this->presenter->response(['data' => $this->detail($node)], 200);
      }
    }

    $node = $this->entityTypeManager()->getStorage('node')->create([
      'type' => $bundle,
      // The owner always comes from the session, never from the request body.
      'uid' => $account->id(),
      'status' => 1,
    ]);
    $errors = $this->input->apply($node, $payload, $account, TRUE);
    if ($errors) {
      return $this->presenter->error('validation_failed', 'Please review the highlighted fields.', 422, $errors);
    }
    $violations = $this->input->violations($node);
    if ($violations) {
      return $this->presenter->error('validation_failed', 'Please review the highlighted fields.', 422, $violations);
    }
    if ($bundle === 'coffee_bean' && \Drupal::service('coffee_journal_api.orders')->duplicate($node)) {
      return $this->presenter->error('duplicate_coffee', 'This coffee already exists. Select it from the search to add an order.', 409, ['title' => 'Choose the existing coffee above.']);
    }
    $node->save();
    $this->keyValueExpirable()->setWithExpire($lock, $node->uuid(), 86400);
    return $this->presenter->response(['data' => $this->detail($node)], 201);
  }

  /**
   * Updates a node, rejecting stale form versions.
   */
  private function updateNode(string $uuid, string $bundle, Request $request): JsonResponse {
    $node = $this->presenter->loadNode($uuid, $bundle);
    if (!$node || !$node->access('view', $this->currentUser())) {
      return $this->presenter->error('not_found', 'This entry is unavailable.', 404);
    }
    if (!$node->access('update', $this->currentUser())) {
      return $this->presenter->error('forbidden', 'You cannot edit this entry.', 403);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->presenter->error('invalid_request', 'Expected a JSON object.', 400);
    }
    $version = $payload['version'] ?? NULL;
    if (!is_int($version)) {
      return $this->presenter->error('invalid_request', 'Include the version you edited.', 400);
    }
    if ($version !== (int) $node->getChangedTime()) {
      return $this->presenter->error(
        'conflict',
        'This entry changed somewhere else. Reload to see the latest version.',
        409
      );
    }
    $errors = $this->input->apply($node, $payload, $this->currentUser(), FALSE);
    if ($errors) {
      return $this->presenter->error('validation_failed', 'Please review the highlighted fields.', 422, $errors);
    }
    $violations = $this->violations($node);
    if ($violations) {
      return $this->presenter->error('validation_failed', 'Please review the highlighted fields.', 422, $violations);
    }
    $node->setNewRevision(FALSE);
    $node->save();
    return $this->presenter->response(['data' => $this->detail($node)]);
  }

  /**
   * Writes a field only when the account may edit it.
   */
  private function assign(NodeInterface $node, string $field, mixed $value): void {
    if (!$node->hasField($field) || !$node->get($field)->access('edit', $this->currentUser())) {
      return;
    }
    $node->set($field, $value);
  }

  /**
   * Returns per-field validation messages keyed by contract name.
   */
  private function violations(NodeInterface $node): array {
    return $this->input->violations($node);
  }

  /**
   * Presents the saved entity using the shared contract.
   */
  private function detail(NodeInterface $node): array {
    return $node->bundle() === 'coffee_bean'
      ? $this->presenter->beanDetail($node, $this->currentUser())
      : $this->presenter->brewDetail($node, $this->currentUser());
  }

  /**
   * Decodes a bounded JSON object body.
   */
  private function decode(Request $request): ?array {
    $body = (string) $request->getContent();
    if ($body === '' || strlen($body) > 65536) {
      return NULL;
    }
    try {
      $payload = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($payload) && !array_is_list($payload) ? $payload : NULL;
  }

  /**
   * Returns expirable storage used for idempotent creates.
   */
  private function keyValueExpirable(): object {
    return $this->keyValueFactory->get('coffee_journal_api');
  }

}
