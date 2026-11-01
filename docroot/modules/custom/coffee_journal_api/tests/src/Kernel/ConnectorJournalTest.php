<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Kernel;

use Drupal\coffee_journal_api\Controller\ConnectorController;
use Drupal\coffee_journal_api\Controller\HeadlessReadController;
use Drupal\coffee_journal_api\Controller\McpController;
use Drupal\coffee_journal_api\Service\CreationProvenance;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Real persistence, ownership, authentication and immutable provenance coverage.
 */
#[Group('coffee_journal_api')]
#[RunTestsInSeparateProcesses]
final class ConnectorJournalTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'node', 'file', 'text', 'filter', 'options', 'coffee_journal_api'];

  private User $owner;
  private User $other;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('coffee_journal_api', ['coffee_journal_connection', 'coffee_journal_create_receipt']);
    $this->installConfig(['system', 'user', 'node', 'filter']);
    NodeType::create(['type' => 'coffee_bean', 'name' => 'Coffee'])->save();
    foreach (array_merge(CreationProvenance::FIELDS, ['field_brand_roaster']) as $name) {
      FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => $name, 'type' => 'string'])->save();
      FieldConfig::create(['entity_type' => 'node', 'field_name' => $name, 'bundle' => 'coffee_bean'])->save();
    }
    Role::load('authenticated')->grantPermission('access content')->grantPermission('create coffee_bean content')
      ->grantPermission('edit own coffee_bean content')->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->owner = User::create(['name' => 'pilot', 'status' => 1]);
    $this->owner->save();
    $this->other = User::create(['name' => 'other', 'status' => 1]);
    $this->other->save();
    $this->container->get('current_user')->setAccount($this->owner);
    new Settings(['hash_salt' => 'kernel-only', 'coffee_journal_connector_account_uuid' => $this->owner->uuid(), 'coffee_journal_connector_app_url' => 'https://journal.example']);
  }

  public function testPartialUpdatesRespectOwnershipAndScope(): void {
    foreach (['field_is_finished', 'field_worth_reordering'] as $name) {
      FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => $name, 'type' => 'boolean'])->save();
      FieldConfig::create(['entity_type' => 'node', 'field_name' => $name, 'bundle' => 'coffee_bean'])->save();
    }
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_overall_taste_rating', 'type' => 'decimal', 'settings' => ['precision' => 3, 'scale' => 1]])->save();
    FieldConfig::create(['entity_type' => 'node', 'field_name' => 'field_overall_taste_rating', 'bundle' => 'coffee_bean', 'settings' => ['min' => 0, 'max' => 5]])->save();
    $connections = $this->container->get('coffee_journal_api.connections');
    $connection = $connections->verify($connections->issue()['token']);
    $journal = $this->container->get('coffee_journal_api.connector_journal');
    $node = Node::create(['type' => 'coffee_bean', 'uid' => $this->owner->id(), 'title' => 'Keep title', 'field_brand_roaster' => 'Keep brand', 'status' => 1]);
    $node->save();
    $patch = ['uuid' => $node->uuid(), 'finished' => TRUE, 'worth_reordering' => TRUE, 'rating' => 4.5];
    $this->assertSame(200, $journal->update($connection, $patch)->getStatusCode());
    $saved = Node::load($node->id());
    $this->assertSame(4.5, (float) $saved->get('field_overall_taste_rating')->value);
    $this->assertSame(422, $journal->update($connection, ['uuid' => $node->uuid(), 'rating' => 6])->getStatusCode());
    $this->assertSame(200, $journal->update($connection, ['uuid' => $node->uuid(), 'finished' => FALSE])->getStatusCode());
    $this->assertFalse((bool) Node::load($node->id())->get('field_is_finished')->value);
    $this->assertSame('Keep title', $saved->label());
    $this->assertSame('Keep brand', $saved->get('field_brand_roaster')->value);
    $this->assertTrue((bool) $saved->get('field_is_finished')->value);
    $this->assertTrue((bool) $saved->get('field_worth_reordering')->value);
    $this->assertSame(422, $journal->update($connection, ['uuid' => $node->uuid(), 'finished' => 'yes'])->getStatusCode());
    $this->assertSame(400, $journal->update($connection, ['uuid' => $node->uuid()])->getStatusCode());
    $readonly = $connection;
    $readonly['scopes'] = ['beans:read', 'beans:create'];
    $this->assertSame(403, $journal->update($readonly, $patch)->getStatusCode());
    $connection['account'] = $this->other;
    $this->assertSame(404, $journal->update($connection, $patch)->getStatusCode());
  }

  public function testCredentialLifecycleAndRouteIsolation(): void {
    $connections = $this->container->get('coffee_journal_api.connections');
    $issued = $connections->issue();
    $verified = $connections->verify($issued['token']);
    $this->assertSame($this->owner->uuid(), $verified['account']->uuid());
    $stored = $this->container->get('database')->select('coffee_journal_connection', 'c')->fields('c')->execute()->fetchAssoc();
    $this->assertSame(hash('sha256', $issued['token']), $stored['token_hash']);
    $this->assertStringNotContainsString($issued['token'], json_encode($stored));
    $auth = $this->container->get('coffee_journal_api.connector_auth');
    $request = Request::create('/api/integrations/v1/beans');
    $this->assertFalse($auth->applies($request));
    $this->assertSame(401, ConnectorController::create($this->container)->handle($request, 'list')->getStatusCode());
    $request->headers->set('Authorization', 'Bearer ' . $issued['token']);
    $this->assertTrue($auth->applies($request));
    $this->assertSame($this->owner->id(), $auth->authenticate($request)->id());
    $request->attributes->set('_route_object', new Route('/', [], [], ['_auth' => ['cookie']]));
    $this->assertFalse($auth->appliesToRoutedRequest($request, TRUE));
    $request->attributes->set('_route_object', new Route('/', [], [], ['_auth' => ['coffee_connector']]));
    $this->assertTrue($auth->appliesToRoutedRequest($request, TRUE));
    $this->assertFalse($auth->applies(Request::create('/api/v1/beans', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued['token']])));
    $connections->revoke($issued['id']);
    $this->assertNull($connections->verify($issued['token']));
    $expired = $connections->issue();
    $this->container->get('database')->update('coffee_journal_connection')->fields(['expires' => 1])->condition('id', $expired['id'])->execute();
    $this->assertNull($connections->verify($expired['token']));
    $active = $connections->issue();
    $this->owner->block()->save();
    $this->assertNull($connections->verify($active['token']));
    $this->owner->activate()->save();
    new Settings(['coffee_journal_connector_account_uuid' => $this->other->uuid()]);
    $this->assertNull($connections->verify($active['token']));
  }

  public function testCreateValidationReplayAndImmutableProvenance(): void {
    $connections = $this->container->get('coffee_journal_api.connections');
    $issued = $connections->issue();
    $connection = $connections->verify($issued['token']);
    $journal = $this->container->get('coffee_journal_api.connector_journal');
    $payload = ['request_id' => 'kernel-request-01', 'title' => ' Kenya AA ', 'brand' => 'Roaster'];
    $created = $journal->create($connection, $payload);
    $this->assertSame(201, $created->getStatusCode(), $created->getContent());
    $data = $this->body($created)['data'];
    $this->assertSame('Kenya AA', $data['title']);
    $this->assertSame('ai_connector', $data['provenance']['channel']);
    $this->assertSame('chatgpt', $data['provenance']['provider']);
    $this->assertSame('https://journal.example/beans/' . $data['id'], $data['url']);
    $this->assertStringNotContainsString($connection['id'], $created->getContent());
    $this->assertStringContainsString('no-store', $created->headers->get('Cache-Control'));
    $retry = $journal->create($connection, array_reverse($payload, TRUE));
    $this->assertSame(200, $retry->getStatusCode());
    $this->assertSame($data['id'], $this->body($retry)['data']['id']);
    $this->assertSame(409, $journal->create($connection, array_replace($payload, ['title' => 'Different']))->getStatusCode());
    $this->assertSame(422, $journal->create($connection, ['request_id' => 'invalid-0001'])->getStatusCode());
    foreach (['uid', 'provenance', 'creation_provider', 'field_creation_provider'] as $key) {
      $this->assertSame(400, $journal->create($connection, $payload + [$key => 'spoof'])->getStatusCode());
    }
    $node = $this->container->get('coffee_journal_api.presenter')->loadNode($data['id'], 'coffee_bean');
    $this->assertFalse($node->get('field_creation_provider')->access('edit', $this->owner));
    $this->assertFalse($node->get('field_creation_connection')->access('view', $this->owner));
    // Alternate entity-write paths cannot forge creation metadata either.
    $node->setTitle('Human edit')->set('field_creation_channel', 'app')->set('field_creation_provider', 'claude')->save();
    $this->assertSame('chatgpt', $node->get('field_creation_provider')->value);
    $connections->revoke($issued['id']);
    $this->assertSame('chatgpt', $this->body($journal->detail($connection, $node->uuid()))['data']['provenance']['provider']);
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute());
    $node->delete();
    $this->assertSame(409, $journal->create($connection, $payload)->getStatusCode());
  }

  public function testOwnerScopingEvenWithAdminPermissionsAndManualOrigin(): void {
    $this->owner->addRole($this->adminRole())->save();
    $issued = $this->container->get('coffee_journal_api.connections')->issue();
    $connection = $this->container->get('coffee_journal_api.connections')->verify($issued['token']);
    $own = Node::create(['type' => 'coffee_bean', 'title' => 'Manual', 'uid' => $this->owner->id(), 'status' => 1,
      'field_creation_channel' => 'ai_connector', 'field_creation_provider' => 'claude']);
    $own->save();
    $other = Node::create(['type' => 'coffee_bean', 'title' => 'Foreign', 'uid' => $this->other->id(), 'status' => 1]);
    $other->save();
    $journal = $this->container->get('coffee_journal_api.connector_journal');
    $this->assertSame(404, $journal->detail($connection, $other->uuid())->getStatusCode());
    $list = $this->body($journal->listing($connection, []));
    $this->assertSame([$own->uuid()], array_column($list['data'], 'id'));
    $this->assertSame('app', $list['data'][0]['provenance']['channel']);
    $this->assertNull($list['data'][0]['provenance']['provider']);
    $this->assertSame(400, $journal->listing($connection, ['before' => ['1']])->getStatusCode());
    $this->assertSame([], $this->body($journal->listing($connection, ['q' => 'missing']))['data']);
    $readonly = $connection;
    $readonly['scopes'] = ['beans:read'];
    $request = Request::create('/api/integrations/v1/beans', 'POST');
    $request->attributes->set('_coffee_connection', $readonly);
    $this->assertSame(403, ConnectorController::create($this->container)->handle($request, 'create')->getStatusCode());
    // Simulate an old row whose empty metadata must not change on edit.
    $this->container->get('database')->delete('node__field_creation_channel')->condition('entity_id', $own->id())->execute();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$own->id()]);
    $historical = Node::load($own->id());
    $historical->setTitle('Older coffee edited')->save();
    $this->assertSame('unknown', $this->container->get('coffee_journal_api.provenance')->present($historical)['channel']);
  }

  public function testDatabaseFailureRollsBackNodeAndReceipt(): void {
    $issued = $this->container->get('coffee_journal_api.connections')->issue();
    $connection = $this->container->get('coffee_journal_api.connections')->verify($issued['token']);
    $database = $this->container->get('database');
    // Force an insert failure after the receipt is written, before node commit.
    $database->schema()->dropTable('node_field_data');
    try {
      $this->container->get('coffee_journal_api.connector_journal')->create($connection, ['request_id' => 'rollback-0001', 'title' => 'Fails']);
      $this->fail('Expected the database failure.');
    }
    catch (\Drupal\Core\Entity\EntityStorageException) {
      $this->assertSame(0, (int) $database->select('coffee_journal_create_receipt', 'r')->countQuery()->execute()->fetchField());
      $this->assertSame(0, (int) $database->select('node', 'n')->countQuery()->execute()->fetchField());
    }
  }

  public function testMcpHandshakeToolsAndConnectionScopedSessions(): void {
    $connections = $this->container->get('coffee_journal_api.connections');
    $issued = $connections->issue('claude');
    $connection = $connections->verify($issued['token']);
    $controller = McpController::create($this->container);
    $request = function (array $body, ?string $session = NULL, ?array $context = NULL) use ($connection): Request {
      $request = Request::create('https://journal.example/api/integrations/v1/mcp', 'POST', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
      ], content: json_encode($body));
      if ($session) {
        $request->headers->set('Mcp-Session-Id', $session);
        $request->headers->set('Mcp-Protocol-Version', '2025-06-18');
      }
      $request->attributes->set('_coffee_connection', $context ?? $connection);
      return $request;
    };
    $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
      'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'Kernel test', 'version' => '1.0'],
    ]];
    $response = $controller->handle($request($initialize));
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $session = $response->headers->get('Mcp-Session-Id');
    $this->assertNotEmpty($session);
    $response = $controller->handle($request(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session));
    $this->assertSame(202, $response->getStatusCode());
    $response = $controller->handle($request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session));
    $body = $this->mcpBody($response);
    $this->assertSame(['get_coffee_options', 'list_coffees', 'get_coffee', 'update_coffee', 'create_coffee'], array_column($body['result']['tools'], 'name'));
    $create = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
      'name' => 'create_coffee', 'arguments' => ['request_id' => 'mcp-kernel-0001', 'title' => 'Claude coffee'],
    ]];
    $response = $controller->handle($request($create, $session));
    $body = $this->mcpBody($response);
    $this->assertFalse($body['result']['isError'] ?? FALSE, $response->getContent());
    $this->assertSame('claude', $body['result']['structuredContent']['data']['provenance']['provider']);
    $create['id'] = 4;
    $response = $controller->handle($request($create, $session));
    $replay = $this->mcpBody($response);
    $this->assertSame($body['result']['structuredContent']['data']['id'], $replay['result']['structuredContent']['data']['id']);
    $response = $controller->handle($request(['jsonrpc' => '2.0', 'id' => 40, 'method' => 'tools/call', 'params' => [
      'name' => 'update_coffee', 'arguments' => ['uuid' => $body['result']['structuredContent']['data']['id'], 'brand' => 'Updated roaster'],
    ]], $session));
    $updated = $this->mcpBody($response);
    $this->assertFalse($updated['result']['isError'] ?? FALSE, json_encode($updated));
    $this->assertSame('Updated roaster', $updated['result']['structuredContent']['data']['brand']);
    $create['params']['arguments']['field_creation_provider'] = 'chatgpt';
    $create['id'] = 5;
    $response = $controller->handle($request($create, $session));
    $body = $this->mcpBody($response);
    $this->assertTrue(isset($body['error']) || ($body['result']['isError'] ?? FALSE));
    $second = $connections->issue('claude', 1, ['beans:read']);
    $secondConnection = $connections->verify($second['token']);
    $response = $controller->handle($request(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list'], $session, $secondConnection));
    $this->assertSame(404, $response->getStatusCode(), 'Sessions must not cross credentials.');
    $response = $controller->handle($request($initialize, NULL, $secondConnection));
    $readonlySession = $response->headers->get('Mcp-Session-Id');
    $controller->handle($request(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $readonlySession, $secondConnection));
    $response = $controller->handle($request(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], $readonlySession, $secondConnection));
    $this->assertNotContains('update_coffee', array_column($this->mcpBody($response)['result']['tools'], 'name'));
    $this->assertNotContains('create_coffee', array_column($this->mcpBody($response)['result']['tools'], 'name'));
    $foreignOrigin = $request($initialize);
    $foreignOrigin->headers->set('Origin', 'https://foreign.example');
    $this->assertSame(403, $controller->handle($foreignOrigin)->getStatusCode());
  }

  public function testTransportBoundsAndSharedRequestBudget(): void {
    $connections = $this->container->get('coffee_journal_api.connections');
    $issued = $connections->issue();
    $connection = $connections->verify($issued['token']);
    $controller = ConnectorController::create($this->container);
    $request = Request::create('/api/integrations/v1/beans', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('x', 65537));
    $request->attributes->set('_coffee_connection', $connection);
    $this->assertSame(413, $controller->handle($request, 'create')->getStatusCode());
    $request = Request::create('/api/integrations/v1/beans', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
    $request->attributes->set('_coffee_connection', $connection);
    $this->assertSame(415, $controller->handle($request, 'create')->getStatusCode());
    foreach (['[]', 'null', '{', '"text"'] as $body) {
      $request = Request::create('/api/integrations/v1/beans', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
      $request->attributes->set('_coffee_connection', $connection);
      $this->assertSame(400, $controller->handle($request, 'create')->getStatusCode());
    }
    $mcp = McpController::create($this->container);
    $request = Request::create('/api/integrations/v1/mcp', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '[{}]');
    $request->attributes->set('_coffee_connection', $connection);
    $this->assertSame(400, $mcp->handle($request)->getStatusCode());
    $flood = $this->container->get('flood');
    for ($i = 0; $i < 60; $i++) {
      $flood->register('coffee_connector', 60, $connection['id']);
    }
    $this->assertSame(429, $controller->handle($request, 'list')->getStatusCode());
    $this->assertSame(429, $mcp->handle($request)->getStatusCode());
  }

  public function testActionContractCoversSupportedFieldsAndMarksWritesConsequential(): void {
    $schema = \Symfony\Component\Yaml\Yaml::parseFile(DRUPAL_ROOT . '/../docs/ai-connectors/openapi.yml');
    $operation = $schema['paths']['/api/integrations/v1/beans']['post'];
    $this->assertTrue($operation['x-openai-isConsequential']);
    $this->assertSame(['personalBearer' => []], $schema['security'][0]);
    $input = $schema['components']['schemas']['CreateCoffee'];
    $this->assertFalse($input['additionalProperties']);
    $fields = array_keys($input['properties']);
    $expected = array_merge(['request_id'], array_keys(\Drupal\coffee_journal_api\Service\HeadlessPresenter::BEAN_FIELDS));
    sort($fields);
    sort($expected);
    $this->assertSame($expected, $fields);
  }

  public function testChatgptLauncherIsOnlyExposedForTheEnabledPilot(): void {
    $connections = $this->container->get('coffee_journal_api.connections');
    $url = 'https://chatgpt.com/plugins/plugins_6ae669f6c27081919016002e9934f120';
    $this->assertSame($url, $connections->chatgptAppUrl($this->owner));
    $this->assertNull($connections->chatgptAppUrl($this->other));
    $this->assertNull($connections->chatgptAppUrl(new \Drupal\Core\Session\AnonymousUserSession()));
    $read = HeadlessReadController::create($this->container);
    $this->assertSame($url, $this->body($read->profile())['data']['chatgpt_app_url']);
    $this->container->get('current_user')->setAccount($this->other);
    $this->assertNull($this->body($read->profile())['data']['chatgpt_app_url']);
    $this->owner->block()->save();
    $this->assertNull($connections->chatgptAppUrl($this->owner));
    $this->owner->activate()->save();
    new Settings(['hash_salt' => 'kernel-only']);
    $this->assertNull($connections->chatgptAppUrl($this->owner));
  }

  private function mcpBody(\Symfony\Component\HttpFoundation\Response $response): array {
    $body = $response->getContent();
    if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
      $body = '';
      ob_start(static function (string $chunk) use (&$body): string { $body .= $chunk; return ''; });
      try {
        $response->sendContent();
      }
      finally {
        ob_end_flush();
      }
    }
    if (str_starts_with($body, 'event:') || str_starts_with($body, 'data:')) {
      preg_match_all('/^data: (.+)$/m', $body, $matches);
      $body = end($matches[1]) ?: '';
    }
    return json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  private function adminRole(): string {
    $role = Role::create(['id' => 'test_admin', 'label' => 'Test admin']);
    $role->setIsAdmin(TRUE)->save();
    return $role->id();
  }

  private function body(JsonResponse $response): array {
    return json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
