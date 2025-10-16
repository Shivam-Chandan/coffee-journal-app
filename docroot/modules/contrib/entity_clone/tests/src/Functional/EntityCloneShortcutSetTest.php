<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\shortcut\Entity\ShortcutSet;
use Drupal\Tests\BrowserTestBase;

/**
 * Create a shortcut set and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneShortcutSetTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'shortcut'];

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
    'clone shortcut_set entity',
    'administer shortcuts',
  ];

  /**
   * An administrative user with permission to configure shortcuts settings.
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
   * Test shortcut set entity clone.
   */
  public function testShortcutSetEntityClone() {
    $edit = [
      'id' => 'test_shortcut_set_cloned',
      'label' => 'Test shortcut set cloned',
    ];
    $this->drupalGet('entity_clone/shortcut_set/default');
    $this->submitForm($edit, 'Clone');

    $shortcut_sets = \Drupal::entityTypeManager()
      ->getStorage('shortcut_set')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $shortcut_set = reset($shortcut_sets);
    $this->assertInstanceOf(ShortcutSet::class, $shortcut_set, 'Test default shortcut set cloned found in database.');
  }

}
