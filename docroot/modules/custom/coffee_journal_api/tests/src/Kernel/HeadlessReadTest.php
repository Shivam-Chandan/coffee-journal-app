<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Kernel;

use Drupal\coffee_journal_api\Controller\HeadlessEngagementController;
use Drupal\coffee_journal_api\Controller\HeadlessReadController;
use Drupal\coffee_journal_api\Controller\HeadlessWriteController;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the headless contracts against real entities and privacy grants.
 */
#[Group('coffee_journal_api')]
final class HeadlessReadTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'options',
    'datetime',
    'serialization',
    'jsonapi',
    'brew_privacy',
    'coffee_journal_likes',
    'coffee_journal_bookmark',
    'coffee_journal_api',
  ];

  /**
   * The owner of the private journal fixture.
   */
  private User $owner;

  /**
   * A different unprivileged account.
   */
  private User $other;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    // These modules declare hook_schema() in their .module file, so the usual
    // installSchema() helper cannot find it. Create the tables from the same
    // real definitions the modules install with.
    foreach (['coffee_journal_likes', 'coffee_journal_bookmark'] as $module) {
      foreach (($module . '_schema')() as $table => $definition) {
        $this->container->get('database')->schema()->createTable($table, $definition);
      }
    }
    $this->installConfig(['system', 'user', 'node', 'filter']);
    foreach (['anonymous', 'authenticated'] as $id) {
      $role = Role::load($id);
      $role->grantPermission('access content')->save();
    }
    foreach (['coffee_bean', 'brew_recipe'] as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => $bundle])->save();
    }
    // Node permissions only exist once their bundles do.
    Role::load('authenticated')
      ->grantPermission('create coffee_bean content')
      ->grantPermission('create brew_recipe content')
      ->grantPermission('edit own coffee_bean content')
      ->grantPermission('edit own brew_recipe content')
      ->grantPermission('delete own coffee_bean content')
      ->grantPermission('delete own brew_recipe content')
      ->save();
    $this->createField('field_is_public', 'boolean', ['brew_recipe']);
    $this->createField('field_brand_roaster', 'string', ['coffee_bean']);
    $this->createField('field_roast_level', 'string', ['coffee_bean']);
    $this->createField('field_overall_taste_rating', 'integer', ['coffee_bean']);
    $this->createField('field_coffee_weight', 'decimal', ['brew_recipe']);
    $this->createField('field_water_weight', 'decimal', ['brew_recipe']);
    $this->createField('field_brew_method', 'string', ['brew_recipe']);
    $this->createField('field_share_caption', 'string_long', ['brew_recipe']);
    $this->createField('field_coffee_bean_ref', 'entity_reference', ['brew_recipe']);
    // Reserve UID 1 so neither test account bypasses normal access controls.
    User::create(['uid' => 1, 'name' => 'administrator', 'status' => 1])->save();
    $this->owner = User::create(['name' => 'owner', 'status' => 1, 'mail' => 'owner@example.com']);
    $this->owner->save();
    $this->other = User::create(['name' => 'other', 'status' => 1, 'mail' => 'other@example.com']);
    $this->other->save();
  }

  /**
   * Public and personal lists remain isolated across real account switches.
   */
  public function testSessionJournalAndFeedIsolation(): void {
    $private = $this->node('coffee_bean', $this->owner, NULL, 'private owner bean');
    $this->node('coffee_bean', $this->other, NULL, 'other bean');
    $this->node('brew_recipe', $this->owner, FALSE, 'private brew');
    $public = $this->node('brew_recipe', $this->other, TRUE, 'public brew');

    $this->setAccount(new AnonymousUserSession());
    $read = HeadlessReadController::create($this->container);
    $this->assertSame(['authenticated' => FALSE, 'user' => NULL], $this->body($read->session())['data']);
    $this->assertSame(401, $read->beans(Request::create('/'))->getStatusCode());
    $this->assertSame(401, $read->profile()->getStatusCode());
    $this->assertSame(401, $read->saved()->getStatusCode());
    $feed = $read->feed(Request::create('/'));
    $this->assertSame([$public->uuid()], array_column($this->body($feed)['data'], 'id'));
    $this->assertStringContainsString('no-store', $feed->headers->get('Cache-Control'));
    $this->assertSame('no-store', $feed->headers->get('CDN-Cache-Control'));
    $this->assertSame(404, $read->bean($private->uuid())->getStatusCode());

    $this->setAccount($this->owner);
    $read = HeadlessReadController::create($this->container);
    $this->assertSame((int) $this->owner->id(), $this->body($read->session())['data']['user']['id']);
    $journal = $this->body($read->beans(Request::create('/')));
    $this->assertSame([$private->uuid()], array_column($journal['data'], 'id'));
    $this->assertSame(1, $this->body($read->profile())['data']['stats']['beans']);

    $this->setAccount($this->other);
    $read = HeadlessReadController::create($this->container);
    $this->assertNotContains($private->uuid(), array_column($this->body($read->beans(Request::create('/')))['data'], 'id'));
    $this->assertSame(404, $read->bean($private->uuid())->getStatusCode());
    // Feed explicitly remains public-only even for the owner of a private brew.
    $this->assertSame([$public->uuid()], array_column($this->body($read->feed(Request::create('/')))['data'], 'id'));
  }

  /**
   * New inserts do not shift already-paginated records or bypass the bound.
   */
  public function testCursorIsBoundedStableAndValidated(): void {
    $this->setAccount($this->owner);
    for ($i = 0; $i < 22; $i++) {
      $this->node('coffee_bean', $this->owner, NULL, 'Bean ' . $i);
    }
    $read = HeadlessReadController::create($this->container);
    $first = $this->body($read->beans(Request::create('/')));
    $this->assertCount(20, $first['data']);
    // A new insert must not shift items between the older-ID pages.
    $this->node('coffee_bean', $this->owner, NULL, 'new bean');
    $second = $this->body($read->beans(Request::create('/?before=' . $first['meta']['next'])));
    $this->assertCount(2, $second['data']);
    $this->assertNull($second['meta']['next']);
    $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    foreach (['before=-1', 'before=abc', 'before[]=2', 'before=99999999999999999999999'] as $query) {
      $this->assertSame(400, $read->beans(Request::create('/?' . $query))->getStatusCode());
    }
  }

  /**
   * Writes enforce ownership, validation, conflicts and idempotency.
   */
  public function testWritesEnforceOwnershipAndConflicts(): void {
    $this->setAccount($this->owner);
    $write = HeadlessWriteController::create($this->container);

    // A create requires a usable idempotency key.
    $this->assertSame(400, $write->createBean($this->json(['title' => 'A'], []))->getStatusCode());

    $created = $write->createBean($this->json(['title' => 'Kenya AA', 'brand' => 'Roaster'], ['Idempotency-Key' => 'key-123456']));
    $this->assertSame(201, $created->getStatusCode());
    $bean = $this->body($created)['data'];

    // Replaying the same key returns the first result instead of duplicating.
    $replay = $write->createBean($this->json(['title' => 'Kenya AA'], ['Idempotency-Key' => 'key-123456']));
    $this->assertSame(200, $replay->getStatusCode());
    $this->assertSame($bean['id'], $this->body($replay)['data']['id']);
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['type' => 'coffee_bean', 'title' => 'Kenya AA']));

    // A stale version must not silently overwrite a newer edit.
    $this->assertSame(409, $write->updateBean($bean['id'], $this->json(['version' => 1, 'brand' => 'X'], []))->getStatusCode());
    $updated = $write->updateBean($bean['id'], $this->json(['version' => $bean['version'], 'brand' => 'Updated'], []));
    $this->assertSame('Updated', $this->body($updated)['data']['brand']);

    // The owner is taken from the session, never from the request body.
    $hijack = $write->createBean($this->json(['title' => 'Hijack', 'uid' => (int) $this->other->id()], ['Idempotency-Key' => 'key-654321']));
    $node = $this->container->get('entity_type.manager')->getStorage('node')
      ->loadByProperties(['type' => 'coffee_bean', 'title' => 'Hijack']);
    $this->assertSame((int) $this->owner->id(), (int) reset($node)->getOwnerId());
    $this->assertSame(201, $hijack->getStatusCode());

    $this->setAccount($this->other);
    $write = HeadlessWriteController::create($this->container);
    $this->assertSame(404, $write->updateBean($bean['id'], $this->json(['version' => 1], []))->getStatusCode());
    $this->assertSame(404, $write->deleteBean($bean['id'])->getStatusCode());
    // Referencing another account's private bean must fail validation.
    $rejected = $write->createBrew($this->json(['title' => 'Steal', 'bean' => $bean['id']], ['Idempotency-Key' => 'key-abcdef']));
    $this->assertSame(422, $rejected->getStatusCode());
    $this->assertArrayHasKey('bean', $this->body($rejected)['error']['fields']);
  }

  /**
   * Bean deletion refuses to destroy referencing brew history.
   */
  public function testBeanDeletionProtectsRelatedBrews(): void {
    $this->setAccount($this->owner);
    $bean = $this->node('coffee_bean', $this->owner, NULL, 'Referenced');
    $brew = $this->node('brew_recipe', $this->owner, FALSE, 'Uses bean');
    $brew->set('field_coffee_bean_ref', ['target_id' => $bean->id()])->save();
    $write = HeadlessWriteController::create($this->container);

    $blocked = $write->deleteBean($bean->uuid());
    $this->assertSame(409, $blocked->getStatusCode());
    $this->assertNotNull(Node::load($bean->id()));

    $this->assertSame(200, $write->deleteBrew($brew->uuid())->getStatusCode());
    $this->assertSame(200, $write->deleteBean($bean->uuid())->getStatusCode());
    $this->assertNull(Node::load($bean->id()));
  }

  /**
   * Visibility is an explicit state and controls feed exposure.
   */
  public function testVisibilityIsExplicitAndRevocable(): void {
    $this->setAccount($this->owner);
    $brew = $this->node('brew_recipe', $this->owner, FALSE, 'Shareable');
    $write = HeadlessWriteController::create($this->container);

    $this->assertSame(422, $write->setVisibility($brew->uuid(), $this->json(['is_public' => 'yes'], []))->getStatusCode());
    $published = $write->setVisibility($brew->uuid(), $this->json(['is_public' => TRUE, 'caption' => 'Shared'], []));
    $this->assertTrue($this->body($published)['data']['is_public']);

    $this->setAccount(new AnonymousUserSession());
    $read = HeadlessReadController::create($this->container);
    $this->assertSame([$brew->uuid()], array_column($this->body($read->feed(Request::create('/')))['data'], 'id'));

    // Making it private must remove it from every reachable representation.
    $this->setAccount($this->owner);
    HeadlessWriteController::create($this->container)
      ->setVisibility($brew->uuid(), $this->json(['is_public' => FALSE], []));
    $this->setAccount(new AnonymousUserSession());
    $read = HeadlessReadController::create($this->container);
    $this->assertSame([], $this->body($read->feed(Request::create('/')))['data']);
    $this->assertSame(404, $read->brew($brew->uuid())->getStatusCode());
  }

  /**
   * Engagement endpoints express a desired state and are safe to retry.
   */
  public function testEngagementIsIdempotent(): void {
    $this->setAccount($this->owner);
    $brew = $this->node('brew_recipe', $this->owner, TRUE, 'Likeable');
    $engagement = HeadlessEngagementController::create($this->container);
    $first = $this->body($engagement->like($brew->uuid()))['data']['engagement'];
    $second = $this->body($engagement->like($brew->uuid()))['data']['engagement'];
    $this->assertSame(1, $first['likes']);
    $this->assertSame($first, $second);
    $removed = $this->body($engagement->unlike($brew->uuid()))['data']['engagement'];
    $this->assertSame(0, $removed['likes']);
    $this->assertFalse($removed['liked']);
  }

  /**
   * Form metadata comes from Drupal field configuration.
   */
  public function testFormOptionsDescribeConfiguredFields(): void {
    $this->setAccount($this->owner);
    $data = $this->body(HeadlessReadController::create($this->container)->formOptions())['data'];
    $this->assertArrayHasKey('coffee_bean', $data);
    $this->assertArrayHasKey('brew_recipe', $data);
    $this->assertNotEmpty($data['brew_methods']);
  }

  /**
   * Creates a field storage plus instances for the given bundles.
   */
  private function createField(string $name, string $type, array $bundles): void {
    $settings = $type === 'entity_reference' ? ['target_type' => 'node'] : [];
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => $name,
      'type' => $type,
      'settings' => $settings,
    ])->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $bundle,
        'field_name' => $name,
      ])->save();
    }
  }

  /**
   * Creates an isolated fixture with real node-access records.
   */
  private function node(string $bundle, User $owner, ?bool $public, string $title): Node {
    $values = [
      'type' => $bundle,
      'title' => $title,
      'uid' => $owner->id(),
      'status' => 1,
    ];
    if ($public !== NULL) {
      $values['field_is_public'] = $public;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Switches the acting account for subsequent access checks.
   */
  private function setAccount(object $account): void {
    $this->container->get('current_user')->setAccount($account);
    // A single PHP process reuses per-entity access results, which a real
    // request boundary would not. Reset them so each account is re-evaluated.
    $manager = $this->container->get('entity_type.manager');
    $manager->getAccessControlHandler('node')->resetCache();
    $manager->getStorage('node')->resetCache();
  }

  /**
   * Builds a JSON request with optional headers.
   */
  private function json(array $payload, array $headers): Request {
    $request = Request::create('/', 'POST', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $request;
  }

  /**
   * Decodes a controller response for contract assertions.
   */
  private function body(JsonResponse $response): array {
    return json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
