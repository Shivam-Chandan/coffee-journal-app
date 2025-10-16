<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * Create a role and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneRoleTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'user'];

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
    'administer permissions',
    'clone user_role entity',
  ];

  /**
   * An administrative user with permission to configure roles settings.
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
   * Test role entity clone.
   */
  public function testRoleEntityClone() {
    $edit = [
      'label' => 'Test role for clone',
      'id' => 'test_role_for_clone',
    ];
    $this->drupalGet("/admin/people/roles/add");
    $this->submitForm($edit, 'Save');

    $roles = \Drupal::entityTypeManager()
      ->getStorage('user_role')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $role = reset($roles);

    $edit = [
      'id' => 'test_role_cloned',
      'label' => 'Test role cloned',
    ];
    $this->drupalGet('entity_clone/user_role/' . $role->id());
    $this->submitForm($edit, 'Clone');

    $roles = \Drupal::entityTypeManager()
      ->getStorage('user_role')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $role = reset($roles);
    $this->assertInstanceOf(Role::class, $role, 'Test role cloned found in database.');
  }

}
