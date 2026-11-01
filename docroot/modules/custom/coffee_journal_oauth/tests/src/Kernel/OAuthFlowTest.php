<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_oauth\Kernel;

use Drupal\coffee_journal_oauth\Controller\OAuthController;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\{FieldConfig, FieldStorageConfig};
use PHPUnit\Framework\Attributes\{Group, RunTestsInSeparateProcesses};
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises the real League grants, signature verification and persisted families.
 */
#[Group('coffee_journal_oauth')]
#[RunTestsInSeparateProcesses]
final class OAuthFlowTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'node', 'file', 'text', 'filter', 'options', 'coffee_journal_api', 'coffee_journal_oauth'];
  private User $owner;
  private User $other;
  private array $runtime;
  private array $keys = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('coffee_journal_oauth', ['coffee_journal_oauth_grant', 'coffee_journal_oauth_token']);
    $this->installConfig(['system', 'user', 'node', 'filter']);
    NodeType::create(['type' => 'coffee_bean', 'name' => 'Coffee'])->save();
    Role::load('authenticated')->grantPermission('access content')->grantPermission('create coffee_bean content')->save();
    foreach (\Drupal\coffee_journal_api\Service\CreationProvenance::FIELDS as $name) {
      FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => $name, 'type' => 'string'])->save();
      FieldConfig::create(['entity_type' => 'node', 'field_name' => $name, 'bundle' => 'coffee_bean'])->save();
    }
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $this->owner = User::create(['name' => 'owner', 'mail' => 'owner@example.com', 'status' => 1]);
    $this->owner->save();
    $this->other = User::create(['name' => 'other', 'status' => 1]);
    $this->other->save();
    $this->container->get('current_user')->setAccount($this->owner);
    $pair = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($pair, $private);
    $public = openssl_pkey_get_details($pair)['key'];
    foreach (['private' => $private, 'public' => $public] as $type => $contents) {
      $path = tempnam(sys_get_temp_dir(), 'coffee-oauth-');
      file_put_contents($path, $contents);
      chmod($path, 0600);
      $this->keys[$type] = $path;
    }
    $this->runtime = ['hash_salt' => bin2hex(random_bytes(32)), 'coffee_journal_connector_account_uuid' => $this->owner->uuid(),
      'coffee_journal_oauth' => [
        'enabled' => TRUE, 'issuer' => 'https://journal.example', 'client_id' => 'chatgpt-test-client',
        'redirect_uri' => 'https://chatgpt.com/connector/oauth/test-client',
        'private_key' => $this->keys['private'], 'public_key' => $this->keys['public'], 'encryption_key' => bin2hex(random_bytes(32)),
      ]];
    new Settings($this->runtime);
  }

  protected function tearDown(): void {
    foreach ($this->keys as $path) { unlink($path); }
    parent::tearDown();
  }

  private function query(array $overrides = []): array {
    return array_replace([
      'response_type' => 'code', 'client_id' => 'chatgpt-test-client',
      'redirect_uri' => 'https://chatgpt.com/connector/oauth/test-client', 'state' => 'test-state-0001',
      'resource' => 'https://journal.example/api/integrations/v1/mcp', 'scope' => 'beans:read beans:create',
      'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), TRUE)), '+/', '-_'), '='),
    ], $overrides);
  }

  private function code(array $overrides = []): string {
    $response = $this->container->get('coffee_journal_oauth.server')->complete($this->query($overrides), $this->owner, TRUE);
    $this->assertSame(302, $response->getStatusCode());
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
    $this->assertSame('test-state-0001', $params['state']);
    return $params['code'];
  }

  private function exchange(string $code, array $overrides = []): \Symfony\Component\HttpFoundation\Response {
    return $this->container->get('coffee_journal_oauth.server')->token(array_replace([
      'grant_type' => 'authorization_code', 'client_id' => 'chatgpt-test-client',
      'redirect_uri' => 'https://chatgpt.com/connector/oauth/test-client', 'code' => $code,
      'code_verifier' => str_repeat('v', 64), 'resource' => 'https://journal.example/api/integrations/v1/mcp',
    ], $overrides));
  }

  public function testCodeExchangeRefreshAndDisconnect(): void {
    $server = $this->container->get('coffee_journal_oauth.server');
    $store = $this->container->get('coffee_journal_oauth.grants');
    $this->assertSame('not_connected', $store->status($this->owner)['status']);
    $code = $this->code();
    $this->assertSame('not_connected', $store->status($this->owner)['status'], 'A code alone must not report authorization.');
    $response = $this->exchange($code);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $tokens = json_decode($response->getContent(), TRUE);
    $connection = $server->verify($tokens['access_token']);
    $this->assertSame($this->owner->uuid(), $connection['account']->uuid());
    $this->assertSame('chatgpt', $connection['provider']);
    $this->assertSame('authorized', $store->status($this->owner)['status']);
    $this->assertSame(400, $this->exchange($code)->getStatusCode());
    $refresh = $server->token(['grant_type' => 'refresh_token', 'client_id' => 'chatgpt-test-client',
      'refresh_token' => $tokens['refresh_token'], 'resource' => 'https://journal.example/api/integrations/v1/mcp']);
    $this->assertSame(200, $refresh->getStatusCode(), $refresh->getContent());
    $renewed = json_decode($refresh->getContent(), TRUE);
    $this->assertSame($connection['id'], $server->verify($renewed['access_token'])['id']);
    $this->assertNull($server->verify($tokens['access_token']));
    $store->disconnect($this->owner);
    $this->assertNull($server->verify($renewed['access_token']));
    $this->assertSame('reconnect_required', $store->status($this->owner)['status']);
    $this->assertSame(400, $server->token(['grant_type' => 'refresh_token', 'client_id' => 'chatgpt-test-client',
      'refresh_token' => $renewed['refresh_token'], 'resource' => 'https://journal.example/api/integrations/v1/mcp'])->getStatusCode());
  }

  public function testRefreshReplayRevokesTheEntireFamily(): void {
    $server = $this->container->get('coffee_journal_oauth.server');
    $tokens = json_decode($this->exchange($this->code())->getContent(), TRUE);
    $body = ['grant_type' => 'refresh_token', 'client_id' => 'chatgpt-test-client', 'refresh_token' => $tokens['refresh_token'], 'resource' => 'https://journal.example/api/integrations/v1/mcp'];
    $renewed = json_decode($server->token($body)->getContent(), TRUE);
    $this->assertNotNull($server->verify($renewed['access_token']));
    $this->assertSame(400, $server->token($body)->getStatusCode());
    $this->assertNull($server->verify($renewed['access_token']));
  }

  public function testPkceResourceRedirectAndScopeEnforcement(): void {
    $code = $this->code();
    foreach ([['code_verifier' => str_repeat('x', 64)], ['resource' => 'https://another.example/mcp'], ['redirect_uri' => 'https://attacker.example/callback'], ['client_id' => 'unknown-client']] as $changes) {
      $this->assertGreaterThanOrEqual(400, $this->exchange($code, $changes)->getStatusCode());
    }
    $this->assertSame(200, $this->exchange($code)->getStatusCode(), 'Invalid attempts must not consume a valid code.');
    foreach ([['code_challenge_method' => 'plain'], ['resource' => 'wrong'], ['redirect_uri' => 'https://attacker.example'], ['scope' => 'admin'], ['destination' => 'https://attacker.example'], ['state' => []]] as $changes) {
      try {
        $this->container->get('coffee_journal_oauth.server')->authorize($this->query($changes));
        $this->fail('Expected invalid authorization input to fail.');
      }
      catch (\League\OAuth2\Server\Exception\OAuthServerException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testMetadataDenialAndAccountIsolation(): void {
    $controller = OAuthController::create($this->container);
    $data = json_decode($controller->discovery(Request::create('/'), 'server')->getContent(), TRUE);
    $this->assertSame(['S256'], $data['code_challenge_methods_supported']);
    $this->assertArrayNotHasKey('registration_endpoint', $data);
    $server = $this->container->get('coffee_journal_oauth.server');
    $denied = $server->complete($this->query(), $this->owner, FALSE);
    $this->assertStringContainsString('error=access_denied', $denied->headers->get('Location'));
    $this->assertSame('not_connected', $this->container->get('coffee_journal_oauth.grants')->status($this->owner)['status']);
    $tokens = json_decode($this->exchange($this->code())->getContent(), TRUE);
    $this->assertNull($server->verify($tokens['access_token'] . 'modified'));
    $this->owner->block()->save();
    $this->assertNull($server->verify($tokens['access_token']));
    $this->owner->activate()->save();
    new Settings(array_replace($this->runtime, ['coffee_journal_connector_account_uuid' => $this->other->uuid()]));
    $this->assertNull($server->verify($tokens['access_token']));
  }

  public function testAnonymousMcpChallengeAndStableProfile(): void {
    $tokens = json_decode($this->exchange($this->code())->getContent(), TRUE);
    $connection = $this->container->get('coffee_journal_oauth.server')->verify($tokens['access_token']);
    $controller = \Drupal\coffee_journal_api\Controller\McpController::create($this->container);
    $call = function (array $body, ?string $session = NULL, ?array $context = NULL) use ($controller) {
      $request = Request::create('https://journal.example/api/integrations/v1/mcp', 'POST', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
      ], content: json_encode($body));
      if ($session) {
        $request->headers->set('Mcp-Session-Id', $session);
        $request->headers->set('Mcp-Protocol-Version', '2025-06-18');
      }
      if ($context) { $request->attributes->set('_coffee_connection', $context); }
      return $controller->handle($request);
    };
    $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
      'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'OAuth test', 'version' => '1'],
    ]];
    $anonymous = $call($init);
    $session = $anonymous->headers->get('Mcp-Session-Id');
    $this->assertSame(200, $anonymous->getStatusCode());
    $call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
    $response = $call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session);
    $tools = $this->rpc($response)['result']['tools'];
    $this->assertCount(5, $tools);
    $this->assertSame('oauth2', $tools[0]['securitySchemes'][0]['type']);
    $profile = array_values(array_filter($tools, static fn ($tool) => $tool['name'] === 'get_profile'))[0];
    $this->assertTrue($profile['_meta']['openai/profile']);
    $invoke = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'get_profile', 'arguments' => new \stdClass()]];
    $body = $this->rpc($call($invoke, $session));
    $this->assertTrue($body['result']['isError']);
    $this->assertArrayHasKey('mcp/www_authenticate', $body['result']['_meta']);
    $this->assertArrayNotHasKey('structuredContent', $body['result']);
    // A new authenticated namespace cannot reuse an anonymous session.
    $this->assertSame(404, $call(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'], $session, $connection)->getStatusCode());
    $authenticated = $call($init, NULL, $connection)->headers->get('Mcp-Session-Id');
    $call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $authenticated, $connection);
    $body = $this->rpc($call($invoke, $authenticated, $connection));
    $this->assertSame($this->owner->uuid(), $body['result']['structuredContent']['id']);
    $this->assertSame('owner@example.com', $body['result']['structuredContent']['email']);
    $connection['account']->setEmail(NULL)->save();
    $invoke['id'] = 5;
    $body = $this->rpc($call($invoke, $authenticated, $connection));
    $this->assertSame($this->owner->uuid(), $body['result']['structuredContent']['id']);
    $this->assertArrayNotHasKey('email', $body['result']['structuredContent']);
  }

  public function testCreateReplayAfterRefreshAndReadOnlyScopes(): void {
    $server = $this->container->get('coffee_journal_oauth.server');
    $tokens = json_decode($this->exchange($this->code())->getContent(), TRUE);
    $first = $server->verify($tokens['access_token']);
    $journal = $this->container->get('coffee_journal_api.connector_journal');
    $this->installSchema('coffee_journal_api', ['coffee_journal_create_receipt', 'coffee_journal_connection']);
    $payload = ['request_id' => 'refresh-retry-0001', 'title' => 'Coffee from ChatGPT'];
    $created = $journal->create($first, $payload);
    $this->assertSame(201, $created->getStatusCode(), $created->getContent());
    $data = json_decode($created->getContent(), TRUE)['data'];
    $this->assertSame('chatgpt', $data['provenance']['provider']);
    $renewed = json_decode($server->token(['grant_type' => 'refresh_token', 'client_id' => 'chatgpt-test-client', 'refresh_token' => $tokens['refresh_token'], 'resource' => 'https://journal.example/api/integrations/v1/mcp'])->getContent(), TRUE);
    $second = $server->verify($renewed['access_token']);
    $retry = $journal->create($second, $payload);
    $this->assertSame(200, $retry->getStatusCode());
    $this->assertSame($data['id'], json_decode($retry->getContent(), TRUE)['data']['id']);
    $limited = json_decode($this->exchange($this->code(['scope' => 'beans:read']))->getContent(), TRUE);
    $this->assertSame(['beans:read'], $server->verify($limited['access_token'])['scopes']);
    $badRefresh = $server->token(['grant_type' => 'refresh_token', 'client_id' => 'chatgpt-test-client', 'refresh_token' => $limited['refresh_token'],
      'scope' => 'beans:read beans:create', 'resource' => 'https://journal.example/api/integrations/v1/mcp']);
    $this->assertGreaterThanOrEqual(400, $badRefresh->getStatusCode());
  }

  public function testConsentAndBrowserRevocationBoundaries(): void {
    $state = new \Drupal\Core\Form\FormState();
    $form = \Drupal\coffee_journal_oauth\Form\ConsentForm::create($this->container);
    $build = $form->buildForm([], $state, $this->query());
    $this->assertSame('owner@example.com', $this->owner->getEmail());
    $this->assertTrue($build['actions']['allow']['#approved']);
    $this->assertFalse($build['actions']['deny']['#approved']);
    $this->assertSame($this->owner->uuid(), $state->get('owner_uuid'));
    $this->assertFalse($state->has('server'), 'Private keys/server objects must not enter form cache.');
    $this->container->get('current_user')->setAccount($this->other);
    try {
      $form->buildForm([], new \Drupal\Core\Form\FormState(), $this->query());
      $this->fail('A different account must not authorize the pilot.');
    }
    catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) { $this->addToAssertionCount(1); }
    $routes = \Symfony\Component\Yaml\Yaml::parseFile(DRUPAL_ROOT . '/modules/custom/coffee_journal_oauth/coffee_journal_oauth.routing.yml');
    $disconnect = $routes['coffee_journal_oauth.disconnect'];
    $this->assertSame(['cookie'], $disconnect['options']['_auth']);
    $this->assertSame('TRUE', $disconnect['requirements']['_csrf_request_header_token']);
    $this->assertSame('TRUE', $disconnect['requirements']['_user_is_logged_in']);
    $this->assertSame(['DELETE'], $disconnect['methods']);
  }

  public function testSignedTokensAreBoundToIssuerAudienceTimeAndClient(): void {
    $server = $this->container->get('coffee_journal_oauth.server');
    $tokens = json_decode($this->exchange($this->code())->getContent(), TRUE);
    $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(new \Lcobucci\JWT\Signer\Rsa\Sha256(),
      \Lcobucci\JWT\Signer\Key\InMemory::file($this->keys['private']), \Lcobucci\JWT\Signer\Key\InMemory::file($this->keys['public']));
    $original = $config->parser()->parse($tokens['access_token'])->claims();
    $now = new \DateTimeImmutable();
    $claims = ['issuer' => 'https://journal.example', 'audience' => 'https://journal.example/api/integrations/v1/mcp',
      'client' => 'chatgpt-test-client', 'issued' => $now, 'before' => $now, 'expires' => $now->modify('+15 minutes')];
    foreach ([[], ['issuer' => 'https://wrong.example'], ['audience' => 'https://wrong.example/mcp'], ['client' => 'another-client'],
      ['expires' => $now->modify('-1 minute')], ['before' => $now->modify('+1 hour')]] as $overrides) {
      $values = array_replace($claims, $overrides);
      $token = $config->builder()->issuedBy($values['issuer'])->permittedFor($values['audience'])
        ->identifiedBy($original->get('jti'))->relatedTo($original->get('sub'))
        ->issuedAt($values['issued'])->canOnlyBeUsedAfter($values['before'])->expiresAt($values['expires'])
        ->withClaim('scopes', ['beans:read', 'beans:create'])->withClaim('client_id', $values['client'])
        ->getToken($config->signer(), $config->signingKey())->toString();
      if (!$overrides) { $this->assertNotNull($server->verify($token)); }
      else { $this->assertNull($server->verify($token)); }
    }
  }

  public function testConfidentialClientAuthenticationAndFeatureFlag(): void {
    $runtime = $this->runtime;
    $runtime['coffee_journal_oauth']['client_secret'] = bin2hex(random_bytes(32));
    new Settings($runtime);
    $code = $this->code();
    $this->assertGreaterThanOrEqual(400, $this->exchange($code)->getStatusCode());
    $this->assertSame(200, $this->exchange($code, ['client_secret' => $runtime['coffee_journal_oauth']['client_secret']])->getStatusCode());
    $runtime['coffee_journal_oauth']['enabled'] = FALSE;
    new Settings($runtime);
    $this->assertSame(404, OAuthController::create($this->container)->discovery(Request::create('/'), 'resource')->getStatusCode());
    $runtime['coffee_journal_oauth']['discovery_enabled'] = TRUE;
    new Settings($runtime);
    $controller = OAuthController::create($this->container);
    $this->assertSame(200, $controller->discovery(Request::create('/'), 'resource')->getStatusCode());
    $this->assertSame(200, $controller->discovery(Request::create('/'), 'server')->getStatusCode());
    $this->assertSame(404, $controller->token(Request::create('/coffee/oauth/token', 'POST'))->getStatusCode());
    $this->assertSame(404, $controller->authorize(Request::create('/coffee/oauth/authorize'))->getStatusCode());
    $this->assertNull($this->container->get('coffee_journal_oauth.connection_status')->status($this->owner));
  }

  public function testExpiredCodeAndAuthenticationRouteIsolation(): void {
    $code = $this->code();
    $this->container->get('database')->update('coffee_journal_oauth_token')->fields(['expires' => 1])->condition('id', 'code:%', 'LIKE')->execute();
    $this->assertSame(400, $this->exchange($code)->getStatusCode());
    $tokens = json_decode($this->exchange($this->code())->getContent(), TRUE);
    $auth = $this->container->get('coffee_journal_oauth.authentication');
    $request = Request::create('/api/integrations/v1/mcp');
    $request->headers->set('Authorization', 'Bearer ' . $tokens['access_token']);
    $this->assertTrue((bool) $auth->applies($request));
    $this->assertSame($this->owner->id(), $auth->authenticate($request)->id());
    foreach (['/api/v1/session', '/api/v1/me/connections/chatgpt', '/api/integrations/v1/beans', '/jsonapi/node/coffee_bean'] as $path) {
      $other = Request::create($path);
      $other->headers->set('Authorization', 'Bearer ' . $tokens['access_token']);
      $this->assertFalse((bool) $auth->applies($other));
    }
    $invalid = Request::create('/api/integrations/v1/mcp', 'POST');
    $invalid->headers->set('Authorization', 'Bearer invalid');
    $response = $this->container->get('coffee_journal_oauth.gateway')->handle($invalid);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertStringContainsString('resource_metadata=', $response->headers->get('WWW-Authenticate'));
  }

  public function testSigningKeyRotationOverlap(): void {
    $server = $this->container->get('coffee_journal_oauth.server');
    $old = json_decode($this->exchange($this->code())->getContent(), TRUE)['access_token'];
    $pair = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($pair, $private);
    $public = openssl_pkey_get_details($pair)['key'];
    $runtime = $this->runtime;
    $runtime['coffee_journal_oauth']['previous_public_key'] = $this->keys['public'];
    foreach (['private' => $private, 'public' => $public] as $type => $contents) {
      $path = tempnam(sys_get_temp_dir(), 'coffee-rotation-');
      file_put_contents($path, $contents);
      chmod($path, 0600);
      $this->keys['rotated_' . $type] = $path;
      $runtime['coffee_journal_oauth'][$type . '_key'] = $path;
    }
    new Settings($runtime);
    $this->assertNotNull($server->verify($old));
    $new = json_decode($this->exchange($this->code())->getContent(), TRUE)['access_token'];
    $this->assertNotNull($server->verify($new));
    unset($runtime['coffee_journal_oauth']['previous_public_key']);
    new Settings($runtime);
    $this->assertNull($server->verify($old));
    $this->assertNotNull($server->verify($new));
  }

  private function rpc(\Symfony\Component\HttpFoundation\Response $response): array {
    $body = $response->getContent();
    if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
      $body = '';
      ob_start(static function (string $chunk) use (&$body): string { $body .= $chunk; return ''; });
      try { $response->sendContent(); } finally { ob_end_flush(); }
    }
    if (str_contains($body, 'data: ')) {
      preg_match_all('/^data: (.+)$/m', $body, $matches);
      $body = end($matches[1]);
    }
    return json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
  }
}
