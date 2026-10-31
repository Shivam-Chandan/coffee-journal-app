<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_auth\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rendered native authentication using isolated sites and captured mail.
 */
#[Group('coffee_journal_auth')]
#[RunTestsInSeparateProcesses]
final class NativeAuthenticationTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['coffee_journal_auth', 'social_auth_google', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('user.settings')
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', TRUE)
      ->save();
    $this->drupalPlaceBlock('system_messages_block');
  }

  /**
   * The actual theme exposes native login and keeps Google during overlap.
   */
  public function testLoginThemeAndPresentationRollback(): void {
    $this->container->get('theme_installer')->install(['coffee_journal']);
    $this->config('system.theme')->set('default', 'coffee_journal')->save();
    $this->drupalPlaceBlock('system_main_block', ['region' => 'content', 'theme' => 'coffee_journal']);
    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.cj-native-login form.user-login-form');
    $this->assertSession()->linkExists('Forgot password / Set up password');
    $this->assertSession()->linkExists('Create an account');
    $this->assertSession()->elementExists('css', 'a.cj-btn-google[href$="/user/login/google"]');
    $this->assertSession()->responseContains('native-auth.css');
    $this->assertSame(1, substr_count(strtolower($this->getSession()->getPage()->getContent()), '<!doctype html>'));
    $this->assertSession()->responseNotContains('<head-placeholder');

    $this->container->get('module_installer')->uninstall(['coffee_journal_auth']);
    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.cj-native-login');
    $this->assertSession()->elementExists('css', 'details.cj-admin-login form.user-login-form');
    $this->assertSession()->elementExists('css', 'a.cj-btn-google');
  }

  /**
   * Email login preserves identity, rejects bad passwords, and logs out.
   */
  public function testEmailLoginAndLogout(): void {
    $account = $this->drupalCreateUser([], 'native-owner');
    $uuid = $account->uuid();
    $password = $account->passRaw;
    $this->drupalGet('user/login');
    $this->submitForm(['name' => $account->getEmail(), 'pass' => 'incorrect password'], 'Log in');
    $this->assertSession()->pageTextContains('Unrecognized username or password.');

    $this->loginByEmail($account, $password);
    $this->assertSession()->addressEquals('user/' . $account->id());
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('mail', $account->getEmail());
    $this->assertSame($uuid, $this->reloadAccount($account)->uuid());
    $this->drupalLogout();
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->assertSession()->statusCodeEquals(403);

    $account->block()->save();
    $this->drupalGet('user/login');
    $this->submitForm(['name' => $account->getEmail(), 'pass' => $password], 'Log in');
    $this->assertSession()->pageTextContains('has not been activated or is blocked');
    $this->assertSession()->fieldExists('pass');
  }

  /**
   * Captured recovery mail sets a password on the original UID without replay.
   */
  public function testRecoveryAndPasswordSave(): void {
    $account = $this->drupalCreateUser([], 'recovery-owner');
    $account->setLastLoginTime(time() - 3600)->save();
    $uuid = $account->uuid();
    $old_password = $account->passRaw;
    $old_hash = $account->getPassword();
    $this->drupalGet('user/password');
    $this->submitForm(['name' => $account->getEmail()], 'Submit');
    $this->assertSession()->pageTextContains('an email will be sent with instructions');
    $mails = $this->getMails(['id' => 'user_password_reset']);
    $this->assertCount(1, $mails);
    $this->assertSame($old_hash, $this->reloadAccount($account)->getPassword());
    $this->assertSame(1, preg_match('#https?://[^\s]+/user/reset/[^\s]+#', $mails[0]['body'], $matches));
    $reset_url = $matches[0];
    $this->drupalGet($reset_url);
    $this->assertSession()->pageTextContains('This login can be used only once.');
    $this->assertSame($old_hash, $this->reloadAccount($account)->getPassword());
    $this->submitForm([], 'Log in');
    $this->loggedInUser = $account;
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('Password field is required.');

    $password = 'New controlled fixture password 123!';
    $this->submitForm(['pass[pass1]' => $password, 'pass[pass2]' => $password], 'Save');
    $this->assertSession()->pageTextContains('The changes have been saved.');
    $this->assertSame($uuid, $this->reloadAccount($account)->uuid());
    $this->drupalLogout();
    $this->drupalGet($reset_url);
    $this->assertSession()->pageTextContains('either been used or is no longer valid');
    $this->drupalGet('user/login');
    $this->submitForm(['name' => $account->getEmail(), 'pass' => $old_password], 'Log in');
    $this->assertSession()->pageTextContains('Unrecognized username or password.');
    $this->loginByEmail($account, $password);
    $this->assertSession()->addressEquals('user/' . $account->id());
  }

  /**
   * Verified registration rejects existing email and captures activation mail.
   */
  public function testVerifiedRegistrationAndUnknownRecovery(): void {
    $existing = $this->drupalCreateUser([], 'existing-owner');
    $this->drupalGet('user/register');
    $this->assertSession()->fieldNotExists('pass[pass1]');
    $this->submitForm(['name' => 'duplicate-owner', 'mail' => $existing->getEmail()], 'Create new account');
    $this->assertSession()->pageTextContains('is already taken');
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['name' => 'duplicate-owner']));

    $this->drupalGet('user/register');
    $this->submitForm(['name' => 'verified-new-owner', 'mail' => 'verified-new@example.test'], 'Create new account');
    $this->assertSession()->pageTextContains('A welcome message with further instructions has been sent');
    $mails = $this->getMails(['id' => 'user_register_no_approval_required']);
    $this->assertCount(1, $mails);
    $this->assertStringContainsString('/user/reset/', $mails[0]['body']);
    $this->drupalGet('user/login');
    $this->assertSession()->fieldExists('pass');

    $accounts = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => 'verified-new@example.test']);
    $account = reset($accounts);
    $this->assertInstanceOf(UserInterface::class, $account);
    $uuid = $account->uuid();
    $this->assertSame(1, preg_match('#https?://[^\s]+/user/reset/[^\s]+#', $mails[0]['body'], $matches));
    $this->drupalGet($matches[0]);
    $this->submitForm([], 'Log in');
    $this->loggedInUser = $account;
    $password = 'Verified registration fixture password 123!';
    $this->submitForm(['pass[pass1]' => $password, 'pass[pass2]' => $password], 'Save');
    $this->assertSession()->pageTextContains('The changes have been saved.');
    $this->assertSame($uuid, $this->reloadAccount($account)->uuid());
    $this->drupalLogout();
    $this->loginByEmail($account, $password);
    $this->assertSession()->addressEquals('user/' . $account->id());
    $this->drupalLogout();

    $this->drupalGet('user/password');
    $this->submitForm(['name' => 'unknown@example.test'], 'Submit');
    $this->assertSession()->pageTextContains('an email will be sent with instructions');
    $this->assertCount(0, $this->getMails(['id' => 'user_password_reset']));
  }

  /**
   * Signs in through the rendered form with an email rather than a username.
   */
  private function loginByEmail(UserInterface $account, string $password): void {
    $this->drupalGet('user/login');
    $this->submitForm(['name' => $account->getEmail(), 'pass' => $password], 'Log in');
    $this->loggedInUser = $account;
  }

  /**
   * Loads current storage values after an HTTP request changed the account.
   */
  private function reloadAccount(UserInterface $account): UserInterface {
    return $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($account->id());
  }

}
