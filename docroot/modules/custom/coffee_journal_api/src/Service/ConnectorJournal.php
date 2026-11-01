<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provider-neutral owner-scoped journal tools.
 */
final class ConnectorJournal {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly HeadlessPresenter $presenter,
    private readonly JournalInput $input,
    private readonly CreationProvenance $provenance,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {}

  public function options(): JsonResponse {
    return $this->presenter->response(['data' => $this->presenter->formOptions()['coffee_bean'] ?? []]);
  }

  public function listing(array $connection, array $query): JsonResponse {
    $before = $query['before'] ?? NULL;
    $search = $query['q'] ?? '';
    if (($before !== NULL && (!is_string($before) || !ctype_digit($before) || (int) $before < 1 || (string) (int) $before !== $before))
      || !is_string($search) || mb_strlen($search) > 200 || array_diff(array_keys($query), ['before', 'q'])) {
      return $this->presenter->error('invalid_request', 'Use an optional positive before cursor and a search of at most 200 characters.', 400);
    }
    $storage = $this->entities->getStorage('node');
    $select = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'coffee_bean')
      ->condition('uid', $connection['account']->id())->condition('status', 1)->sort('nid', 'DESC')->range(0, 21);
    if ($before !== NULL) {
      $select->condition('nid', (int) $before, '<');
    }
    if (trim($search) !== '') {
      $select->condition('title', trim($search), 'CONTAINS');
    }
    $ids = array_values($select->execute());
    $more = count($ids) > 20;
    $ids = array_slice($ids, 0, 20);
    $data = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($this->owned($node, $connection)) {
        $data[] = $this->presenter->beanCard($node, $connection['account']) + ['url' => $this->url($node)];
      }
    }
    return $this->presenter->response(['data' => $data, 'meta' => ['next' => $more ? (int) end($ids) : NULL]]);
  }

  public function detail(array $connection, string $uuid): JsonResponse {
    $node = $this->presenter->loadNode($uuid, 'coffee_bean');
    return $this->owned($node, $connection)
      ? $this->presenter->response(['data' => $this->present($node, $connection)])
      : $this->presenter->error('not_found', 'This coffee is unavailable.', 404);
  }

  public function create(array $connection, array $payload): JsonResponse {
    $account = $connection['account'];
    if (!$this->entities->getAccessControlHandler('node')->createAccess('coffee_bean', $account)) {
      return $this->presenter->error('forbidden', 'You cannot create this entry.', 403);
    }
    if (array_diff(array_keys($payload), array_merge(array_keys(HeadlessPresenter::BEAN_FIELDS), ['request_id']))) {
      return $this->presenter->error('invalid_request', 'Only documented coffee fields and request_id are accepted.', 400);
    }
    $requestId = $payload['request_id'] ?? NULL;
    if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $requestId)) {
      return $this->presenter->error('invalid_request', 'Supply a stable request_id of 8–64 letters, digits, underscores or hyphens.', 400);
    }
    unset($payload['request_id']);
    foreach ($payload as &$value) {
      if (is_string($value)) {
        $value = trim($value);
      }
    }
    unset($value);
    ksort($payload);
    $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    $id = hash('sha256', $account->uuid() . ':' . $connection['id'] . ':create_coffee:' . $requestId);
    $lockName = 'coffee_connector_create:' . $id;
    if (!$this->lock->acquire($lockName, 120.0)) {
      return $this->presenter->error('in_progress', 'This request is in progress. Retry with the same request_id.', 409);
    }
    $transaction = NULL;
    try {
      $transaction = $this->database->startTransaction();
      $now = $this->time->getCurrentTime();
      $receipt = $this->database->select('coffee_journal_create_receipt', 'r')->fields('r')
        ->condition('id', $id)->execute()->fetchAssoc();
      if ($receipt && (int) $receipt['expires'] > $now) {
        if ($receipt['payload_hash'] !== $hash) {
          return $this->presenter->error('conflict', 'This request_id was already used with different coffee fields.', 409);
        }
        $node = $this->presenter->loadNode($receipt['node_uuid'], 'coffee_bean');
        return $this->owned($node, $connection)
          ? $this->presenter->response(['data' => $this->present($node, $connection)], 200)
          : $this->presenter->error('conflict', 'The previously saved coffee is no longer available. Do not retry as a new operation.', 409);
      }
      $node = $this->entities->getStorage('node')->create(['type' => 'coffee_bean', 'uid' => $account->id(), 'status' => 1]);
      foreach (CreationProvenance::FIELDS as $field) {
        if (!$node->hasField($field)) {
          return $this->presenter->error('unavailable', 'Coffee provenance configuration must be imported before enabling connections.', 503);
        }
      }
      $errors = $this->input->apply($node, $payload, $account, TRUE);
      $errors += $this->input->violations($node);
      if ($errors) {
        return $this->presenter->error('validation_failed', 'Please review the coffee fields.', 422, $errors);
      }
      // The unique receipt and node commit in the same database transaction.
      // A second worker cannot save another node even if the advisory lock expires.
      if ($receipt) {
        $this->database->delete('coffee_journal_create_receipt')->condition('id', $id)->condition('expires', $now, '<=')->execute();
      }
      $this->database->insert('coffee_journal_create_receipt')->fields([
        'id' => $id, 'payload_hash' => $hash, 'node_uuid' => $node->uuid(), 'expires' => $now + 86400,
      ])->execute();
      $this->provenance->markConnector($node, $connection['provider'], $connection['id']);
      $node->save();
      $result = $this->presenter->response(['data' => $this->present($node, $connection)], 201);
      unset($transaction);
      return $result;
    }
    catch (IntegrityConstraintViolationException $exception) {
      $transaction?->rollBack();
      return $this->presenter->error('in_progress', 'Retry with the same request_id.', 409);
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      throw $exception;
    }
    finally {
      // Commit read-only/validation paths before releasing the advisory lock.
      unset($transaction);
      $this->lock->release($lockName);
    }
  }

  public function update(array $connection, array $payload): JsonResponse {
    if (!in_array('beans:update', $connection['scopes'], TRUE)) {
      return $this->presenter->error('forbidden', 'This connection cannot update coffees.', 403);
    }
    $uuid = $payload['uuid'] ?? NULL;
    unset($payload['uuid']);
    if (!is_string($uuid) || !$payload || array_diff(array_keys($payload), array_keys(HeadlessPresenter::BEAN_FIELDS))) {
      return $this->presenter->error('invalid_request', 'Supply a coffee UUID and at least one documented coffee field.', 400);
    }
    $lockName = 'coffee_connector_update:' . hash('sha256', $uuid);
    if (!$this->lock->acquire($lockName, 120.0)) {
      return $this->presenter->error('in_progress', 'This coffee is busy. Retry the same update.', 409);
    }
    try {
      $this->entities->getStorage('node')->resetCache();
      $node = $this->presenter->loadNode($uuid, 'coffee_bean');
      if (!$this->owned($node, $connection)) {
        return $this->presenter->error('not_found', 'This coffee is unavailable.', 404);
      }
      if (!$node->access('update', $connection['account'])) {
        return $this->presenter->error('forbidden', 'You cannot update this coffee.', 403);
      }
      $errors = $this->input->apply($node, $payload, $connection['account'], FALSE);
      $errors += $this->input->violations($node);
      if ($errors) {
        return $this->presenter->error('validation_failed', 'Please review the coffee fields.', 422, $errors);
      }
      $node->save();
      return $this->presenter->response(['data' => $this->present($node, $connection)]);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  private function owned(?NodeInterface $node, array $connection): bool {
    return $node !== NULL && $node->bundle() === 'coffee_bean'
      && (int) $node->getOwnerId() === (int) $connection['account']->id()
      && $node->access('view', $connection['account']);
  }

  private function present(NodeInterface $node, array $connection): array {
    return $this->presenter->beanDetail($node, $connection['account']) + ['url' => $this->url($node)];
  }

  private function url(NodeInterface $node): string {
    $base = Settings::get('coffee_journal_connector_app_url', '');
    return is_string($base) && preg_match('#^https://[^/?\x23]+$#D', $base)
      ? $base . '/beans/' . $node->uuid()
      : $node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

}
