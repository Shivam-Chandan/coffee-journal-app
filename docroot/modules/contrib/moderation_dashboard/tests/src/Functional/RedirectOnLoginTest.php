<?php

namespace Drupal\Tests\moderation_dashboard\Functional;

use Drupal\Core\Url;

/**
 * Tests redirect on login configuration.
 *
 * @group moderation_dashboard
 */
class RedirectOnLoginTest extends ModerationDashboardTestBase {

  /**
   * Tests enabled redirect on login.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testEnabled(): void {
    $this->drupalGet(Url::fromRoute('user.login'));
    $this->submitForm([
      'name' => $this->user->getAccountName(),
      'pass' => $this->user->passRaw,
    ], 'Log in');

    $this->assertSession()->addressEquals("user/{$this->user->id()}/moderation-dashboard");
  }

  /**
   * Tests disabled redirect on login.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testDisabled(): void {
    // Set redirect to disable.
    $this->config('moderation_dashboard.settings')
      ->set('redirect_on_login', FALSE)
      ->save();

    // User is not redirected.
    $this->drupalLogin($this->user);
    $this->assertSession()->addressEquals("user/{$this->user->id()}");
  }

  /**
   * Tests if settings form is working as expected.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   */
  public function testSettingsForm(): void {
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $assert_session = $this->assertSession();

    $this->drupalLogin($admin);
    $this->drupalGet('admin/config/people/moderation_dashboard');

    // Disabling redirect on login.
    $this->submitForm([
      'redirect_on_login' => FALSE,
    ], 'Save configuration');

    $status_message = $assert_session->elementExists('css', 'div[role="contentinfo"]')->getText();
    $this->assertSame('Status message The configuration options have been saved.', $status_message);
    $this->assertFalse($this->config('moderation_dashboard.settings')->get('redirect_on_login'));

    // Enabling redirect on login.
    $this->submitForm([
      'redirect_on_login' => TRUE,
    ], 'Save configuration');

    $status_message = $assert_session->elementExists('css', 'div[role="contentinfo"]')->getText();
    $this->assertSame('Status message The configuration options have been saved.', $status_message);
    $this->assertTrue($this->config('moderation_dashboard.settings')->get('redirect_on_login'));
  }

}
