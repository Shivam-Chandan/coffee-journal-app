<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_auth\Kernel;

use Drupal\coffee_journal_auth\Form\EmailLoginForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises email lookup and core form authentication with real user storage.
 */
#[Group('coffee_journal_auth')]
final class EmailLoginTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'coffee_journal_auth'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    $this->container->get('request_stack')->getCurrentRequest()->server->set('REMOTE_ADDR', '192.0.2.1');
  }

  /**
   * Login resolves the same account without rewriting existing account fields.
   */
  public function testEmailLoginPreservesAccountAndUsernameCompatibility(): void {
    $account = $this->createAccount(2, 'coffee-owner', 'Owner@example.com');
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache();
    $account = User::load($account->id());
    $before = $account->toArray();
    $auth = $this->container->get('coffee_journal_auth.user_auth');
    $this->assertSame($account->id(), $auth->lookupAccount(' Owner@example.com ')->id());
    $this->assertSame($account->id(), $auth->lookupAccount('coffee-owner')->id());
    $this->assertFalse($auth->lookupAccount('owner+alias@example.com'));

    $state = $this->authenticate('Owner@example.com', 'Existing password 123!');
    $this->assertEquals($account->id(), $state->get('uid'));
    $this->assertFalse($state->hasAnyErrors());
    $this->assertEquals($account->id(), $this->authenticate('coffee-owner', 'Existing password 123!')->get('uid'));
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache();
    $this->assertSame($before, User::load($account->id())->toArray());
  }

  /**
   * Legacy conflicting emails must never select an arbitrary matching account.
   */
  public function testUnknownAndAmbiguousEmailsFailClosed(): void {
    $auth = $this->container->get('coffee_journal_auth.user_auth');
    $this->assertFalse($auth->lookupAccount('missing@example.com'));
    $this->assertFalse($auth->lookupAccount(''));
    $this->createAccount(2, 'first-owner', 'shared@example.com');
    // Bypass entity validation to simulate legacy email conflicts.
    $this->createAccount(3, 'second-owner', 'shared@example.com', FALSE);
    $this->assertFalse($auth->lookupAccount('shared@example.com'));
    $this->assertFalse($auth->authenticate('shared@example.com', 'Existing password 123!'));
  }

  /**
   * A username containing @ cannot capture an email identifier.
   */
  public function testEmailCannotBeShadowedByUsername(): void {
    $owner = $this->createAccount(2, 'owner', 'owner@example.com');
    $this->createAccount(3, 'owner@example.com', 'other@example.com');
    $auth = $this->container->get('coffee_journal_auth.user_auth');
    $this->assertSame($owner->id(), $auth->lookupAccount('owner@example.com')->id());
    $this->createAccount(4, 'missing@example.com', 'third@example.com');
    $this->assertFalse($auth->lookupAccount('missing@example.com'));
  }

  /**
   * Neither modern nor legacy credential checking permits blocked accounts.
   */
  public function testBlockedAccountsCannotAuthenticate(): void {
    $account = $this->createAccount(2, 'blocked-owner', 'blocked@example.com', FALSE);
    $auth = $this->container->get('coffee_journal_auth.user_auth');
    $this->assertFalse($auth->authenticateAccount($account, 'Existing password 123!'));
    $this->assertFalse($auth->authenticate('blocked@example.com', 'Existing password 123!'));
    $state = $this->authenticate('blocked@example.com', 'Existing password 123!');
    $this->assertEmpty($state->get('uid'));
    $this->assertTrue($state->hasAnyErrors());
  }

  /**
   * Switching identifiers does not reset core's per-account failure budget.
   */
  public function testEmailAndUsernameShareCoreFloodLimits(): void {
    $this->createAccount(2, 'owner', 'owner@example.com');
    $this->config('user.flood')->set('uid_only', TRUE)->set('user_limit', 2)->save();
    $this->assertTrue($this->authenticate('owner@example.com', 'wrong password')->hasAnyErrors());
    $this->assertTrue($this->authenticate('owner', 'wrong password')->hasAnyErrors());

    $form = [];
    $state = (new FormState())->setValues(['name' => 'owner@example.com', 'pass' => 'Existing password 123!']);
    EmailLoginForm::create($this->container)->validateAuthentication($form, $state);
    $this->assertEmpty($state->get('uid'));
    $this->assertSame('user', $state->get('flood_control_triggered'));
  }

  /**
   * Unknown email failures still count toward the independent IP budget.
   */
  public function testUnknownEmailsRetainCoreIpFloodLimits(): void {
    $this->config('user.flood')->set('ip_limit', 1)->save();
    $this->assertTrue($this->authenticate('missing@example.com', 'wrong password')->hasAnyErrors());
    $this->createAccount(2, 'owner', 'owner@example.com');
    $form = [];
    $state = (new FormState())->setValues(['name' => 'owner@example.com', 'pass' => 'Existing password 123!']);
    EmailLoginForm::create($this->container)->validateAuthentication($form, $state);
    $this->assertEmpty($state->get('uid'));
    $this->assertSame('ip', $state->get('flood_control_triggered'));
  }

  /**
   * The form accepts valid email identifiers longer than core's username limit.
   */
  public function testLongEmailAndRecoveryLinks(): void {
    $mail = str_repeat('a', 50) . '@example.test';
    $this->createAccount(2, 'owner', $mail);
    $form = $this->container->get('form_builder')->getForm(EmailLoginForm::class);
    $this->assertGreaterThanOrEqual(strlen($mail), $form['name']['#maxlength']);
    $this->assertSame('user.pass', $form['account_help']['recovery']['#url']->getRouteName());
    $this->assertSame('user.register', $form['account_help']['register']['link']['#url']->getRouteName());
    $this->assertEquals(2, $this->authenticate($mail, 'Existing password 123!')->get('uid'));
  }

  /**
   * Recovery and global/JSON authentication retain their original integration.
   */
  public function testOnlyRenderedLoginRouteChanges(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $this->assertSame(EmailLoginForm::class, $provider->getRouteByName('user.login')->getDefault('_form'));
    $this->assertSame('\\Drupal\\user\\Form\\UserPasswordForm', $provider->getRouteByName('user.pass')->getDefault('_form'));
    // Global authentication stays username-only, including JSON login callers.
    $this->createAccount(2, 'owner', 'owner@example.com');
    $this->assertFalse($this->container->get('user.auth')->lookupAccount('owner@example.com'));
  }

  /**
   * Saves a controlled account without sending registration mail.
   */
  private function createAccount(int $uid, string $name, string $mail, bool $active = TRUE): User {
    $account = User::create([
      'uid' => $uid,
      'name' => $name,
      'mail' => $mail,
      'status' => $active,
      'pass' => 'Existing password 123!',
    ]);
    $account->save();
    return $account;
  }

  /**
   * Runs the inherited core validation chain without starting a login session.
   */
  private function authenticate(string $identifier, string $password): FormState {
    $form = [];
    $state = (new FormState())->setValues(['name' => $identifier, 'pass' => $password]);
    $login = EmailLoginForm::create($this->container);
    $login->validateAuthentication($form, $state);
    $login->validateFinal($form, $state);
    return $state;
  }

}
