<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.presenter'),
      $container->get('entity_field.manager'),
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
    $errors = $this->applyPayload($node, $bundle, $payload, TRUE);
    if ($errors) {
      return $this->presenter->error('validation_failed', 'Please review the highlighted fields.', 422, $errors);
    }
    $violations = $this->violations($node);
    if ($violations) {
      return $this->presenter->error('validation_failed', 'Please review the highlighted fields.', 422, $violations);
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
    $errors = $this->applyPayload($node, $bundle, $payload, FALSE);
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
   * Copies allowlisted payload keys onto the entity.
   */
  private function applyPayload(NodeInterface $node, string $bundle, array $payload, bool $isNew): array {
    $map = $bundle === 'coffee_bean'
      ? HeadlessPresenter::BEAN_FIELDS
      : HeadlessPresenter::BREW_FIELDS;
    $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);
    $errors = [];
    foreach ($map as $key => $field) {
      if (!isset($definitions[$field])) {
        continue;
      }
      if (!array_key_exists($key, $payload)) {
        if ($isNew && $field === 'title') {
          $errors[$key] = 'A name is required.';
        }
        continue;
      }
      $value = $payload[$key];
      if ($field === 'field_coffee_bean_ref') {
        if ($value === NULL || $value === '') {
          $node->set($field, NULL);
          continue;
        }
        if (!is_string($value)) {
          $errors[$key] = 'Choose one of your coffees.';
          continue;
        }
        $bean = $this->presenter->loadNode($value, 'coffee_bean');
        // Referencing a bean the account cannot see would leak private data.
        if (!$bean || !$bean->access('view', $this->currentUser())) {
          $errors[$key] = 'Choose one of your coffees.';
          continue;
        }
        $node->set($field, ['target_id' => $bean->id()]);
        continue;
      }
      $type = $definitions[$field]->getType();
      if ($type === 'boolean') {
        if (!is_bool($value)) {
          $errors[$key] = 'Expected true or false.';
          continue;
        }
        $this->assign($node, $field, $value);
        continue;
      }
      if (in_array($type, ['integer', 'decimal', 'float'], TRUE)) {
        if ($value === NULL || $value === '') {
          $this->assign($node, $field, NULL);
          continue;
        }
        if (!is_int($value) && !is_float($value)) {
          $errors[$key] = 'Enter a number.';
          continue;
        }
        $this->assign($node, $field, $type === 'integer' ? (int) round((float) $value) : (float) $value);
        continue;
      }
      if (!is_string($value)) {
        $errors[$key] = 'Expected text.';
        continue;
      }
      $value = trim($value);
      if ($type === 'text_long') {
        // Plain text only; the Drupal UI also restricts these to plain_text.
        $this->assign($node, $field, $value === '' ? NULL : ['value' => $value, 'format' => 'plain_text']);
        continue;
      }
      $this->assign($node, $field, $value === '' ? NULL : $value);
    }
    return $errors;
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
    $bundle = $node->bundle();
    $map = $bundle === 'coffee_bean'
      ? HeadlessPresenter::BEAN_FIELDS
      : HeadlessPresenter::BREW_FIELDS;
    $reverse = array_flip($map);
    $errors = [];
    foreach ($node->validate() as $violation) {
      $property = explode('.', $violation->getPropertyPath())[0];
      $key = $reverse[$property] ?? $property;
      $errors[$key] = (string) $violation->getMessage();
    }
    return $errors;
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
