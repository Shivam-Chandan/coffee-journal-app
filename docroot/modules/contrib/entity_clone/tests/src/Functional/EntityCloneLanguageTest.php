<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;

/**
 * Create an language and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneLanguageTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'language'];

  /**
   * Theme to enable by default.
   *
   * @var string
   */
  protected $defaultTheme = 'claro';

  /**
   * Permissions to grant admin user.
   *
   * @var array
   */
  protected $permissions = [
    'administer languages',
    'clone configurable_language entity',
  ];

  /**
   * An administrative user with permission to configure languages settings.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Sets the test up.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser($this->permissions);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Test language entity clone.
   */
  public function testLanguageEntityClone() {
    $edit = [
      'predefined_langcode' => 'fr',
    ];
    $this->drupalGet("/admin/config/regional/language/add");
    $this->submitForm($edit, 'Add language');

    $languages = \Drupal::entityTypeManager()
      ->getStorage('configurable_language')
      ->loadByProperties([
        'id' => 'fr',
      ]);
    $language = reset($languages);

    $edit = [
      'id' => 'test_language_cloned',
      'label' => 'French language cloned',
    ];
    $this->drupalGet('entity_clone/configurable_language/' . $language->id());
    $this->submitForm($edit, 'Clone');

    $languages = \Drupal::entityTypeManager()
      ->getStorage('configurable_language')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $language = reset($languages);
    $this->assertInstanceOf(ConfigurableLanguage::class, $language, 'Test language cloned found in database.');
  }

}
