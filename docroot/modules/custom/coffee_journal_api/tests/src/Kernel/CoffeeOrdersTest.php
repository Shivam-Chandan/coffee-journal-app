<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Kernel;

use Drupal\coffee_journal_api\Controller\CoffeeOrderController;
use Drupal\coffee_journal_api\Drush\Commands\CoffeeOrderCommands;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Console\Output\BufferedOutput;

/** Real purchase lifecycle, retry, rollback and account isolation coverage. */
#[Group('coffee_journal_api')]
final class CoffeeOrdersTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'node', 'file', 'text', 'filter', 'datetime', 'brew_privacy', 'coffee_journal_api'];
  private User $owner;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('coffee_journal_api', ['coffee_journal_create_receipt']);
    $this->installConfig(['system', 'user', 'node', 'filter']);
    foreach (['coffee_bean', 'coffee_order'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }
    foreach ([
      'field_purchase_date' => 'datetime', 'field_quantity' => 'decimal',
      'field_quantity_unit' => 'string', 'field_form_factor' => 'string',
      'field_is_finished' => 'boolean', 'field_order_completed' => 'timestamp',
      'field_completion_reason' => 'string', 'field_order_source' => 'integer',
      'field_coffee_bean_ref' => 'entity_reference', 'field_brand_roaster' => 'string',
      'field_roast_level' => 'string', 'field_overall_taste_rating' => 'integer',
      'field_estate' => 'string', 'field_bitterness_rating' => 'integer',
      'field_acidity_rating' => 'integer', 'field_note_clarity_rating' => 'integer',
      'field_flavour_notes' => 'string_long', 'field_worth_reordering' => 'boolean',
      'field_creation_channel' => 'string', 'field_creation_provider' => 'string', 'field_creation_connection' => 'string',
    ] as $name => $type) {
      $settings = $type === 'entity_reference' ? ['target_type' => 'node'] : ($type === 'datetime' ? ['datetime_type' => 'date'] : []);
      FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => $name, 'type' => $type, 'settings' => $settings])->save();
      $bundles = str_starts_with($name, 'field_order_') || in_array($name, ['field_coffee_bean_ref', 'field_completion_reason']) ? ['coffee_order']
        : (in_array($name, ['field_purchase_date', 'field_quantity', 'field_quantity_unit', 'field_form_factor', 'field_is_finished']) ? ['coffee_order', 'coffee_bean'] : ['coffee_bean']);
      foreach ($bundles as $bundle) {
        FieldConfig::create(['entity_type' => 'node', 'field_name' => $name, 'bundle' => $bundle,
          'required' => $name === 'field_purchase_date',
          'settings' => $type === 'entity_reference' ? ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['coffee_bean' => 'coffee_bean']]] : [],
        ])->save();
      }
    }
    Role::load('authenticated')->grantPermission('access content')->grantPermission('create coffee_order content')->grantPermission('create coffee_bean content')
      ->grantPermission('edit own coffee_order content')->grantPermission('edit own coffee_bean content')->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->owner = User::create(['name' => 'owner', 'status' => 1]);
    $this->owner->save();
    $this->container->get('current_user')->setAccount($this->owner);
  }

  private function bean(): Node {
    $bean = Node::create(['type' => 'coffee_bean', 'uid' => $this->owner->id(), 'status' => 1,
      'title' => 'Mullayangiri', 'field_brand_roaster' => 'Toffee Coffee Roaster',
      'field_overall_taste_rating' => 4, 'field_purchase_date' => '2026-06-11',
      'field_quantity' => 250, 'field_quantity_unit' => 'g', 'field_form_factor' => 'whole_bean',
    ]);
    $bean->save();
    return $bean;
  }

  private function request(array $values, string $key): Request {
    $request = Request::create('/', 'POST', [], [], [], [], json_encode($values, JSON_THROW_ON_ERROR));
    $request->headers->set('Idempotency-Key', $key);
    return $request;
  }

  public function testOrdersCompletePreviousAndKeepOneRating(): void {
    $bean = $this->bean();
    $service = $this->container->get('coffee_journal_api.orders');
    $this->assertCount(1, $service->history($bean));
    $controller = CoffeeOrderController::create($this->container);
    $payload = ['purchase_date' => '2026-07-30', 'quantity' => 250, 'quantity_unit' => 'g', 'form_factor' => 'whole_bean', 'finished' => FALSE];
    $response = $controller->createOrder($bean->uuid(), $this->request($payload, 'july-order-1'));
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE)['data'];
    $this->assertSame(4.0, (float) $data['rating']);
    $this->assertSame(2, $data['order_count']);
    $this->assertFalse($data['orders'][0]['finished']);
    $this->assertTrue($data['orders'][1]['finished']);
    $this->assertSame('superseded', $data['orders'][1]['completion_reason']);
    $this->assertSame(201, $controller->createOrder($bean->uuid(), $this->request($payload, 'july-order-1'))->getStatusCode());
    $this->assertCount(2, $service->history($bean));
    $this->assertSame(409, $controller->createOrder($bean->uuid(), $this->request(array_replace($payload, ['quantity' => 500]), 'july-order-1'))->getStatusCode());
    // A second bag on the same date is a real order, not an import duplicate.
    $this->assertSame(201, $controller->createOrder($bean->uuid(), $this->request($payload, 'july-order-2'))->getStatusCode());
    $history = $service->history($bean);
    $this->assertCount(3, $history);
    $this->assertCount(1, array_filter($history, fn ($order) => !$order->get('field_is_finished')->value));
    $this->assertSame(201, $controller->createOrder($bean->uuid(), $this->request(array_replace($payload, ['purchase_date' => '2026-01-10']), 'historical-1'))->getStatusCode());
    $this->assertCount(1, array_filter($service->history($bean), fn ($order) => !$order->get('field_is_finished')->value));
    $current = $service->history($bean)[0];
    $this->assertSame(200, $controller->completeOrder($current->uuid(), $this->request([], 'complete-current'))->getStatusCode());
    $this->assertCount(0, array_filter($service->history($bean), fn ($order) => !$order->get('field_is_finished')->value));
    $this->assertSame(4, (int) Node::load($bean->id())->get('field_overall_taste_rating')->value);
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'coffee_bean']));
  }

  public function testValidationIsolationAndAtomicRollback(): void {
    $bean = $this->bean();
    $service = $this->container->get('coffee_journal_api.orders');
    $controller = CoffeeOrderController::create($this->container);
    $this->assertSame(400, $controller->createOrder($bean->uuid(), $this->request(['rating' => 1], 'invalid-rating'))->getStatusCode());
    $this->assertSame(422, $controller->createOrder($bean->uuid(), $this->request(['purchase_date' => 'invalid'], 'invalid-date'))->getStatusCode());
    try {
      $service->write($bean, 'rollback-order', [], function () use ($service, $bean) {
        $order = $service->fromBean($bean);
        $order->set('field_purchase_date', '2026-10-16')->save();
        throw new \RuntimeException('Simulated failure after save');
      });
      $this->fail('The write should have failed.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Simulated failure after save', $e->getMessage());
    }
    $history = $service->history($bean);
    $this->assertCount(1, $history);
    $this->assertFalse((bool) $history[0]->get('field_is_finished')->value);
    $other = User::create(['name' => 'other', 'status' => 1]);
    $other->save();
    $this->container->get('current_user')->setAccount($other);
    $this->assertSame(404, $controller->createOrder($bean->uuid(), $this->request(['purchase_date' => '2026-10-16'], 'other-account'))->getStatusCode());
    $this->assertSame(404, $controller->completeOrder($history[0]->uuid(), $this->request([], 'other-account'))->getStatusCode());
    $this->assertFalse($history[0]->access('view', $other));
  }

  public function testConsolidationPreservesBagsAndOriginalsAndCanBeRepeated(): void {
    $this->owner->setEmail('cleanup@example.com')->save();
    $original = $this->bean();
    $duplicate = Node::create(['type' => 'coffee_bean', 'uid' => $this->owner->id(), 'status' => 1,
      'title' => 'Mullayangiri Estate Coffee', 'field_brand_roaster' => 'Toffee Coffee Roasters',
      'field_overall_taste_rating' => 5, 'field_purchase_date' => '2026-07-30',
      'field_quantity' => 250, 'field_quantity_unit' => 'g', 'field_form_factor' => 'whole_bean',
    ]);
    $duplicate->save();
    NodeType::create(['type' => 'brew_recipe', 'name' => 'Brew'])->save();
    FieldConfig::create(['entity_type' => 'node', 'field_name' => 'field_coffee_bean_ref', 'bundle' => 'brew_recipe'])->save();
    $brew = Node::create(['type' => 'brew_recipe', 'title' => 'Saved brew', 'uid' => $this->owner->id(), 'field_coffee_bean_ref' => $duplicate->id()]);
    $brew->save();
    $db = $this->container->get('database');
    // Minimal real unique-pair schema verifies merging an already-saved pair.
    $db->schema()->createTable('coffee_journal_bookmark', [
      'fields' => ['id' => ['type' => 'serial', 'not null' => TRUE], 'uid' => ['type' => 'int', 'not null' => TRUE], 'nid' => ['type' => 'int', 'not null' => TRUE], 'created' => ['type' => 'int', 'not null' => TRUE]],
      'primary key' => ['id'], 'unique keys' => ['uid_nid' => ['uid', 'nid']],
    ]);
    foreach ([$original->id(), $duplicate->id()] as $nid) {
      $db->insert('coffee_journal_bookmark')->fields(['uid' => $this->owner->id(), 'nid' => $nid, 'created' => time()])->execute();
    }
    $command = new CoffeeOrderCommands();
    $command->setOutput(new BufferedOutput());
    $command->consolidate('cleanup@example.com', ['apply' => FALSE]);
    $this->assertTrue(Node::load($duplicate->id())->isPublished());
    $command->consolidate('cleanup@example.com', ['apply' => TRUE]);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $canonical = $storage->load($original->id());
    $this->assertSame('Mullayangiri Estate Coffee', $canonical->label());
    $this->assertSame('Toffee Coffee Roaster', $canonical->get('field_brand_roaster')->value);
    $this->assertSame('Mullayangiri Estate', $canonical->get('field_estate')->value);
    $this->assertSame(5, (int) $canonical->get('field_overall_taste_rating')->value);
    $this->assertFalse($storage->load($duplicate->id())->isPublished());
    $this->assertSame($canonical->id(), $storage->load($brew->id())->get('field_coffee_bean_ref')->target_id);
    $this->assertSame(1, (int) $db->select('coffee_journal_bookmark', 'b')->countQuery()->execute()->fetchField());
    $this->assertSame((int) $canonical->id(), (int) $db->select('coffee_journal_bookmark', 'b')->fields('b', ['nid'])->execute()->fetchField());
    $service = $this->container->get('coffee_journal_api.orders');
    $this->assertCount(2, $service->history($canonical));
    $this->assertSame($canonical->uuid(), $this->container->get('coffee_journal_api.presenter')->loadNode($duplicate->uuid(), 'coffee_bean')->uuid());
    $canonical->set('field_overall_taste_rating', 2)->save();
    $command->consolidate('cleanup@example.com', ['apply' => TRUE]);
    $this->assertSame(2, (int) Node::load($canonical->id())->get('field_overall_taste_rating')->value);
    $this->assertCount(2, $service->history($canonical));
    $this->assertCount(1, $storage->loadByProperties(['type' => 'coffee_bean', 'status' => 1]));
  }

  public function testConnectorRepeatPurchaseDoesNotOverwriteCoffeeRating(): void {
    $bean = $this->bean();
    $journal = $this->container->get('coffee_journal_api.connector_journal');
    $connection = ['account' => $this->owner, 'id' => 'test-connection', 'provider' => 'claude', 'scopes' => ['beans:read', 'beans:create']];
    $payload = ['request_id' => 'connector-order-1', 'title' => 'Mullayangiri', 'brand' => 'Toffee Coffee Roasters',
      'purchase_date' => '2026-07-30', 'quantity' => 250, 'quantity_unit' => 'g', 'form_factor' => 'whole_bean', 'rating' => 1];
    $response = $journal->create($connection, $payload);
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE)['data'];
    $this->assertSame($bean->uuid(), $data['id']);
    $this->assertSame(4.0, (float) $data['rating']);
    $this->assertSame(2, $data['order_count']);
    $this->assertSame(200, $journal->create($connection, $payload)->getStatusCode());
    $this->assertCount(2, $this->container->get('coffee_journal_api.orders')->history($bean));
  }

}
