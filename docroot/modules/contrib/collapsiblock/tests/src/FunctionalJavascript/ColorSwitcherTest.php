<?php

namespace Drupal\Tests\collapsiblock\FunctionalJavascript;

use Drupal\block\BlockInterface;
use Drupal\Core\Config\Config;
use Drupal\user\UserInterface;

/**
 * Test the Color Switcher settings for the block.
 *
 * @group collapsiblock
 */
class ColorSwitcherTest extends CollapsiblockJavaScriptTestBase {

  /**
   * The git config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $ginSettings;

  /**
   * A block to test with.
   *
   * @var \Drupal\block\BlockInterface
   */
  protected BlockInterface $collapsiblockTestBlock;

  /**
   * The HTML ID of the test block.
   *
   * @var string
   */
  protected string $collapsiblockTestBlockHtmlId;

  /**
   * An XPath string for the test block's title.
   *
   * @var string
   */
  protected string $collapsiblockTestBlockTitleXpath;

  /**
   * A user with admin permissions to test with.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $collapsiblockGlobalAdminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->collapsiblockGlobalAdminUser = $this->drupalCreateUser([
      'administer site configuration',
      'administer themes',
      'access administration pages',
      'view the administration theme',
    ]);

    $this->drupalLogin($this->collapsiblockGlobalAdminUser);

    // Set the Gin theme as an admin theme.
    \Drupal::service('theme_installer')->install(['gin']);
    $edit['admin_theme'] = 'gin';
    $this->drupalGet('admin/appearance');
    $this->submitForm($edit, 'Save configuration');

    $this->ginSettings = \Drupal::configFactory()->getEditable('gin.settings');

    $this->collapsiblockTestBlock = $this->drupalPlaceBlock('system_powered_by_block', [
      'label_display' => TRUE,
      'theme' => 'gin',
    ]);
    $this->collapsiblockTestBlockHtmlId = 'collapsiblock-wrapper-' . $this->collapsiblockTestBlock->id();
    $this->collapsiblockTestBlockTitleXpath = $this->assertSession()->buildXPathQuery('//*[@id=:blockId]//button', [
      ':blockId' => $this->collapsiblockTestBlockHtmlId,
    ]);
  }

  /**
   * The test for the color switcher logic of the module.
   */
  public function testColorSwitcher() {
    $this->drupalGet('admin/config/user-interface/collapsiblock');
    // Submit the form with new values.
    $configFormValues = [];
    $configFormValues['default_action'] = '2';
    $configFormValues['active_pages'] = 1;
    $configFormValues['slide_speed'] = 500;
    $configFormValues['cookie_lifetime'] = '1';
    $configFormValues['switcher_enabled'] = '0';
    $this->submitForm($configFormValues, 'Save configuration');

    // Test that the form controls now show the updated configuration.
    $this->assertSession()->checkboxNotChecked('edit-default-action-1');
    $this->assertSession()->checkboxChecked('edit-default-action-2');
    $this->assertSession()->checkboxNotChecked('edit-default-action-3');
    $this->assertSession()->checkboxNotChecked('edit-default-action-4');
    $this->assertSession()->checkboxNotChecked('edit-default-action-5');
    $this->assertSession()->checkboxChecked('active_pages');
    $this->assertSession()->optionExists('slide_speed', 500)
      ->hasAttribute('selected');
    $this->assertSession()->fieldValueEquals('cookie_lifetime', 1);
    $this->assertSession()->checkboxNotChecked('switcher_enabled');

    // Set the collapse action.
    $this->setCollapsiblockBlockInstanceSetting($this->collapsiblockTestBlock, 3, 'collapse_action');

    // Visit a page that the block will be displayed on.
    $this->drupalGet('/admin');

    // We expected that class 'collapsiblock-color-switcher' absent.
    $beforeTitle = $this->getSession()->getPage()->find('xpath', $this->collapsiblockTestBlockTitleXpath);
    $this->assertNotNull($beforeTitle);
    $this->assertFalse($beforeTitle->hasClass('collapsiblock-color-switcher'));

    // Enable the color switcher for the Gin theme.
    $this->ginSettings->set('enable_darkmode', 1)->save();
    // Enable the color switcher for module.
    $this->drupalGet('admin/config/user-interface/collapsiblock');
    $configFormValues = [];
    $configFormValues['default_action'] = '2';
    $configFormValues['active_pages'] = 1;
    $configFormValues['slide_speed'] = 500;
    $configFormValues['cookie_lifetime'] = '1';
    $configFormValues['switcher_enabled'] = '1';
    $configFormValues['switcher_class'] = 'gin--dark-mode';
    $this->submitForm($configFormValues, 'Save configuration');

    $this->assertSession()->checkboxChecked('switcher_enabled');
    $this->assertSession()->fieldValueEquals('switcher_class', 'gin--dark-mode');

    // Visit the Admin page and expect that
    // the collapsiblock-color-switcher class was added to a block title.
    $this->drupalGet('/admin');
    $afterTitle = $this->getSession()->getPage()->find('xpath', $this->collapsiblockTestBlockTitleXpath);
    $this->assertNotNull($afterTitle);
    $this->assertTrue($afterTitle->hasClass('collapsiblock-color-switcher'));
  }

}
