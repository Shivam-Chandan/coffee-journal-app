<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\node\NodeInterface;

/**
 * Coffee purchase history shared by browser, Drupal forms and connectors.
 *
 * Legacy purchase fields on beans remain a projection of the latest order for
 * the supported Drupal UI. Ratings are exclusively properties of the bean.
 */
final class CoffeeOrders {

  public const FIELDS = ['form_factor', 'quantity', 'quantity_unit', 'purchase_date', 'finished'];
  public bool $migrating = FALSE;
  private bool $projecting = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
  ) {}

  public function enabled(): bool {
    return $this->entities->getStorage('node_type')->load('coffee_order') !== NULL;
  }

  public function history(NodeInterface $bean): array {
    if (!$this->enabled()) {
      return [];
    }
    $storage = $this->entities->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'coffee_order')->condition('uid', $bean->getOwnerId())
      ->condition('field_coffee_bean_ref', $bean->id())
      ->sort('field_purchase_date', 'DESC')->sort('nid', 'DESC')->execute();
    $storage->resetCache($ids);
    return array_values($storage->loadMultiple($ids));
  }

  public function present(NodeInterface $order): array {
    return [
      'id' => $order->uuid(),
      'purchase_date' => (string) $order->get('field_purchase_date')->value,
      'quantity' => $order->get('field_quantity')->isEmpty() ? NULL : (float) $order->get('field_quantity')->value,
      'quantity_unit' => (string) $order->get('field_quantity_unit')->value,
      'form_factor' => (string) $order->get('field_form_factor')->value,
      'finished' => (bool) $order->get('field_is_finished')->value,
      'completed_at' => $order->get('field_order_completed')->value ? (int) $order->get('field_order_completed')->value : NULL,
      'completion_reason' => (string) $order->get('field_completion_reason')->value,
    ];
  }

  /** Exact normalized identity only; ambiguous aliases are migration decisions. */
  public function duplicate(NodeInterface $bean): ?NodeInterface {
    if (!$bean->hasField('field_brand_roaster')) {
      return NULL;
    }
    $storage = $this->entities->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'coffee_bean')
      ->condition('status', 1)->condition('uid', $bean->getOwnerId())->execute();
    foreach ($storage->loadMultiple($ids) as $existing) {
      if ($existing->id() === $bean->id()) {
        continue;
      }
      $same = TRUE;
      foreach (['title', 'field_brand_roaster', 'field_roast_level'] as $field) {
        $a = $field === 'title' ? $bean->label() : ($bean->hasField($field) ? $bean->get($field)->value : '');
        $b = $field === 'title' ? $existing->label() : ($existing->hasField($field) ? $existing->get($field)->value : '');
        $a = self::normalize((string) $a);
        $b = self::normalize((string) $b);
        if ($field === 'field_brand_roaster') {
          $aliases = ['toffee coffee' => 'toffee coffee roaster', 'toffee coffee roasters' => 'toffee coffee roaster'];
          $a = $aliases[$a] ?? $a;
          $b = $aliases[$b] ?? $b;
        }
        $same = $same && $a === $b;
      }
      if ($same) {
        return $existing;
      }
    }
    return NULL;
  }

  private static function normalize(string $value): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
  }

  /** New coffee entries include their first order, regardless of entry point. */
  public function beanSaved(NodeInterface $bean, bool $new): void {
    if ($this->projecting || $this->migrating || !$this->enabled()) {
      return;
    }
    $history = $this->history($bean);
    if (!$history) {
      $order = $this->fromBean($bean);
      $order->set('field_order_source', $bean->id());
      $order->save();
      return;
    }
    // Supported legacy forms still edit the latest bag's fields on the bean.
    if (!$new && isset($bean->original)) {
      $latest = $history[0];
      $changed = FALSE;
      foreach (self::FIELDS as $key) {
        $field = HeadlessPresenter::BEAN_FIELDS[$key];
        if ($bean->hasField($field) && !$bean->get($field)->equals($bean->original->get($field))) {
          $latest->set($field, $bean->get($field)->getValue());
          $changed = TRUE;
        }
      }
      if ($changed) {
        $latest->save();
      }
    }
  }

  public function fromBean(NodeInterface $bean): NodeInterface {
    $order = $this->entities->getStorage('node')->create([
      'type' => 'coffee_order', 'title' => $bean->label(), 'uid' => $bean->getOwnerId(),
      'status' => 1, 'field_coffee_bean_ref' => $bean->id(),
    ]);
    foreach (self::FIELDS as $key) {
      $field = HeadlessPresenter::BEAN_FIELDS[$key];
      if ($bean->hasField($field)) {
        $value = $bean->get($field)->getValue();
        if ($key === 'purchase_date' && !empty($value[0]['value'])) {
          $value[0]['value'] = substr($value[0]['value'], 0, 10);
        }
        $order->set($field, $value);
      }
    }
    return $order;
  }

  /** Enforced inside node storage's transaction, including direct FAPI saves. */
  public function presave(NodeInterface $order): void {
    if ($this->migrating) {
      return;
    }
    $bean = $order->get('field_coffee_bean_ref')->entity;
    if (!$bean || $bean->bundle() !== 'coffee_bean' || (int) $bean->getOwnerId() !== (int) $order->getOwnerId()) {
      throw new \InvalidArgumentException('An order must reference a coffee owned by the same account.');
    }
    $order->setTitle($bean->label());
    // The parent row serializes direct node/form saves as well as API writes.
    // NodeStorage already opened a transaction before invoking presave hooks.
    $this->database->select('node', 'n')->fields('n', ['nid'])
      ->condition('nid', $bean->id())->forUpdate()->execute()->fetchField();
    $date = (string) $order->get('field_purchase_date')->value;
    $history = $this->history($bean);
    // Backdated entries cannot displace a more recent bag.
    if (!$order->get('field_is_finished')->value && $history && $date < (string) $history[0]->get('field_purchase_date')->value) {
      $order->set('field_is_finished', TRUE)->set('field_order_completed', NULL)->set('field_completion_reason', 'historical');
      return;
    }
    if ($order->get('field_is_finished')->value) {
      if ($order->isNew() || (isset($order->original) && !$order->original->get('field_is_finished')->value)) {
        $order->set('field_order_completed', time());
        if ($order->get('field_completion_reason')->isEmpty()) {
          $order->set('field_completion_reason', 'manual');
        }
      }
      if (!$order->isNew() || ($history && $date < (string) $history[0]->get('field_purchase_date')->value)) {
        return;
      }
    }
    else {
      $order->set('field_order_completed', NULL)->set('field_completion_reason', NULL);
    }
    foreach ($history as $previous) {
      if ($previous->id() !== $order->id() && !$previous->get('field_is_finished')->value) {
        $previous->set('field_is_finished', TRUE)
          ->set('field_completion_reason', 'superseded')->save();
      }
    }
  }

  /** Keeps legacy card fields in sync without creating a second order. */
  public function project(NodeInterface $order): void {
    if ($this->migrating || $this->projecting) {
      return;
    }
    $bean = $order->get('field_coffee_bean_ref')->entity;
    $history = $this->history($bean);
    if (!$history || $history[0]->id() !== $order->id()) {
      return;
    }
    $this->projecting = TRUE;
    try {
      foreach (self::FIELDS as $key) {
        $field = HeadlessPresenter::BEAN_FIELDS[$key];
        $bean->set($field, $order->get($field)->getValue());
      }
      $bean->save();
    }
    finally {
      $this->projecting = FALSE;
    }
  }

  /** Serialize lifecycle writes for a coffee, with atomic retry receipts. */
  public function write(NodeInterface $bean, string $key, array $payload, callable $save): NodeInterface {
    $name = 'coffee_orders:' . $bean->uuid();
    if (!$this->lock->acquire($name, 120)) {
      throw new \RuntimeException('This coffee is being updated. Please retry.', 409);
    }
    $transaction = $this->database->startTransaction();
    try {
      ksort($payload);
      $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
      $id = hash('sha256', 'order:' . $bean->getOwnerId() . ':' . $bean->uuid() . ':' . $key);
      $receipt = $this->database->select('coffee_journal_create_receipt', 'r')->fields('r')->condition('id', $id)->execute()->fetchAssoc();
      if ($receipt) {
        if ($receipt['payload_hash'] !== $hash) {
          throw new \RuntimeException('This submission key was already used with different details.', 409);
        }
        $orders = $this->entities->getStorage('node')->loadByProperties(['uuid' => $receipt['node_uuid'], 'type' => 'coffee_order']);
        if (!$orders) {
          throw new \RuntimeException('The previous order is unavailable.', 409);
        }
        return reset($orders);
      }
      $order = $save();
      $this->database->insert('coffee_journal_create_receipt')->fields([
        'id' => $id, 'payload_hash' => $hash, 'node_uuid' => $order->uuid(), 'expires' => time() + 86400,
      ])->execute();
      return $order;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
      $this->lock->release($name);
    }
  }

}
