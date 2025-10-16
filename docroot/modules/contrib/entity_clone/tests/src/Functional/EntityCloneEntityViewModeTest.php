<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Test an entity view mode clone.
 *
 * @group entity_clone
 */
class EntityCloneEntityViewModeTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'field_ui'];

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
    'clone entity_view_mode entity',
    'administer display modes',
  ];

  /**
   * An administrative user.
   *
   * With permission to configure entity view modes settings.
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
   * Test entity view mode entity clone.
   */
  public function testEntityViewModeEntityClone() {
    $entity_view_modes = \Drupal::entityTypeManager()
      ->getStorage('entity_view_mode')
      ->loadByProperties([
        'id' => 'user.full',
      ]);
    $entity_view_mode = reset($entity_view_modes);

    $edit = [
      'label' => 'User full cloned view mode',
      'id' => 'register_clone',
    ];
    $this->drupalGet('entity_clone/entity_view_mode/' . $entity_view_mode->id());
    $this->submitForm($edit, 'Clone');

    $entity_view_modes = \Drupal::entityTypeManager()
      ->getStorage('entity_view_mode')
      ->loadByProperties([
        'id' => 'user.' . $edit['id'],
      ]);
    $entity_view_mode = reset($entity_view_modes);
    $this->assertInstanceOf(EntityViewMode::class, $entity_view_mode, 'Test entity view mode cloned found in database.');
  }

}
