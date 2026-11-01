<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/** Repeatable purchase backfill and explicitly planned account consolidation. */
final class CoffeeOrderCommands extends DrushCommands {

  #[CLI\Command(name: 'coffee:orders-backfill')]
  public function backfill(): void {
    $orders = \Drupal::service('coffee_journal_api.orders');
    if (!$orders->enabled()) {
      throw new \RuntimeException('Import coffee_order configuration first.');
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'coffee_bean')->condition('status', 1)->execute();
    $count = 0;
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $bean) {
        if (!$orders->history($bean)) {
          $order = $orders->fromBean($bean);
          $order->set('field_order_source', $bean->id());
          // Legacy completed dates are unknown, rather than today's date.
          $orders->migrating = TRUE;
          try {
            $order->save();
            $count++;
          }
          finally {
            $orders->migrating = FALSE;
          }
        }
      }
      $storage->resetCache($chunk);
    }
    $this->output()->writeln("Backfilled $count first orders.");
  }

  #[CLI\Command(name: 'coffee:consolidate')]
  #[CLI\Argument(name: 'email', description: 'Account email to consolidate.')]
  #[CLI\Option(name: 'apply', description: 'Apply the displayed plan; default is read-only preview.')]
  public function consolidate(string $email, array $options = ['apply' => FALSE]): void {
    $orders = \Drupal::service('coffee_journal_api.orders');
    if (!$orders->enabled()) {
      throw new \RuntimeException('Import coffee_order configuration first.');
    }
    $entities = \Drupal::entityTypeManager();
    $users = $entities->getStorage('user')->loadByProperties(['mail' => $email]);
    if (count($users) !== 1) {
      throw new \InvalidArgumentException('Expected exactly one account with this email.');
    }
    $user = reset($users);
    $storage = $entities->getStorage('node');
    $beans = $storage->loadByProperties(['type' => 'coffee_bean', 'uid' => $user->id(), 'status' => 1]);
    $plan = json_decode(file_get_contents(dirname(__DIR__, 3) . '/resources/coffee-consolidation.json'), TRUE, 32, JSON_THROW_ON_ERROR);
    $groups = [];
    foreach ($beans as $bean) {
      $match = NULL;
      foreach ($plan as $index => $group) {
        if (in_array($bean->label(), $group['aliases'], TRUE)) {
          $match = $index;
          break;
        }
      }
      if ($match === NULL) {
        throw new \RuntimeException('Unrecognized coffee; update the reviewed plan before merging: ' . $bean->label());
      }
      if (!in_array((string) $bean->get('field_brand_roaster')->value, ['Toffee Coffee', 'Toffee Coffee Roasters', 'Toffee Coffee Roaster'], TRUE)) {
        throw new \RuntimeException('Unexpected roaster for ' . $bean->label());
      }
      $groups[$match][] = $bean;
    }
    foreach ($groups as $index => $members) {
      $this->output()->writeln($plan[$index]['title'] . ': ' . count($members) . ' entries → 1 coffee (all bag records retained)');
    }
    if (!$options['apply']) {
      $this->output()->writeln('Preview only. Re-run with --apply after reviewing.');
      return;
    }
    if (!\Drupal::lock()->acquire('coffee_consolidation:' . $user->id(), 600)) {
      throw new \RuntimeException('Consolidation is already running.');
    }
    $db = \Drupal::database();
    $transaction = $db->startTransaction();
    $orders->migrating = TRUE;
    try {
      foreach ($groups as $index => $members) {
        usort($members, fn ($a, $b) => (int) $a->id() <=> (int) $b->id());
        $canonical = $members[0];
        if (count($members) === 1 && \Drupal::keyValue('coffee_consolidation')->get('completed:' . $canonical->uuid())) {
          continue;
        }
        $purchases = [];
        foreach ($members as $bean) {
          $history = $orders->history($bean);
          if (!$history) {
            $order = $orders->fromBean($bean);
            $order->set('field_order_source', $bean->id());
            $order->save();
            $history = [$order];
          }
          foreach ($history as $order) {
            $order->set('field_coffee_bean_ref', $canonical->id());
            $purchases[] = $order;
          }
          if ($bean->id() !== $canonical->id()) {
            $refs = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'brew_recipe')
              ->condition('field_coffee_bean_ref', $bean->id())->execute();
            foreach ($storage->loadMultiple($refs) as $brew) {
              $brew->set('field_coffee_bean_ref', $canonical->id())->save();
            }
            foreach (['coffee_journal_bookmark', 'coffee_journal_like'] as $table) {
              if ($db->schema()->tableExists($table)) {
                foreach ($db->select($table, 'r')->fields('r')->condition('nid', $bean->id())->execute()->fetchAll() as $row) {
                  $db->merge($table)->keys(['uid' => $row->uid, 'nid' => $canonical->id()])->fields(['created' => $row->created])->execute();
                }
                $db->delete($table)->condition('nid', $bean->id())->execute();
              }
            }
            \Drupal::keyValue('coffee_consolidation')->set($bean->uuid(), $canonical->uuid());
            // Preserve original fields, notes and ratings for audit/recovery.
            $bean->setUnpublished()->save();
          }
        }
        usort($purchases, fn ($a, $b) => [(string) $b->get('field_purchase_date')->value, (int) $b->id()] <=> [(string) $a->get('field_purchase_date')->value, (int) $a->id()]);
        foreach ($purchases as $position => $order) {
          if ($position > 0) {
            $order->set('field_is_finished', TRUE)->set('field_order_completed', NULL)->set('field_completion_reason', 'consolidated');
          }
          $order->save();
        }
        $latest = $purchases[0];
        // Prefer the latest purchase's recorded coffee-level taste profile.
        $sourceId = $latest->get('field_order_source')->value;
        $source = $sourceId ? $storage->load($sourceId) : NULL;
        if ($source) {
          foreach (['field_overall_taste_rating', 'field_bitterness_rating', 'field_acidity_rating', 'field_note_clarity_rating', 'field_flavour_notes', 'field_worth_reordering'] as $field) {
            if (!$source->get($field)->isEmpty()) {
              $canonical->set($field, $source->get($field)->getValue());
            }
          }
        }
        foreach (\Drupal\coffee_journal_api\Service\CoffeeOrders::FIELDS as $key) {
          $field = \Drupal\coffee_journal_api\Service\HeadlessPresenter::BEAN_FIELDS[$key];
          $canonical->set($field, $latest->get($field)->getValue());
        }
        $canonical->setTitle($plan[$index]['title'])->set('field_brand_roaster', 'Toffee Coffee Roaster');
        if (isset($plan[$index]['estate'])) {
          $canonical->set('field_estate', $plan[$index]['estate']);
        }
        $canonical->setNewRevision(TRUE);
        $canonical->setRevisionLogMessage('Coffee/order consolidation; previous coffee fields retained in the preceding revision.');
        $canonical->save();
        \Drupal::keyValue('coffee_consolidation')->set('completed:' . $canonical->uuid(), TRUE);
      }
      unset($transaction);
      $this->output()->writeln('Consolidation complete. Originals retained unpublished; old API UUIDs resolve to the canonical coffee.');
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
      $orders->migrating = FALSE;
      \Drupal::lock()->release('coffee_consolidation:' . $user->id());
    }
  }

}
