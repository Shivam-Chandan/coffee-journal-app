<?php

namespace Drupal\Tests\moderation_sidebar\Kernel;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Moderation Sidebar config forms.
 *
 * @group moderation_sidebar
 */
class ModerationSidebarConfigFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'content_moderation',
    'moderation_sidebar',
    'moderation_sidebar_test',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests importing fields from default config.
   */
  public function testModerationSidebarConfigForm(): void {
    // Test that the Notification Email address field is on the config page.
    $moderation_sidebar_user = $this->drupalCreateUser([
      'administer moderation sidebar',
    ]);
    $this->drupalLogin($moderation_sidebar_user);
    $this->drupalGet('admin/config/user-interface/moderation-sidebar');
    $this->assertSession()->responseContains('id="edit-workflows-editorial-workflow-disabled-transitions"');
    $this->getSession()->getPage()->checkField('workflows[editorial_workflow][disabled_transitions][publish]');
    $this->submitForm([], 'Save configuration');
    $this->drupalLogout();

    // Verify config matches expected values.
    $config = $this->config('moderation_sidebar.settings')->get('workflows');
    $this->assertArrayHasKey('publish', $config['editorial_workflow']['disabled_transitions']);
    $this->assertEquals('publish', $config['editorial_workflow']['disabled_transitions']['publish']);
  }

}
