<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\BrowserTestBase;

/**
 * Create a filter format and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneFilterFormatTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'filter'];

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
    'clone filter_format entity',
    'administer filters',
  ];

  /**
   * An administrative user.
   *
   * With permission to configure filter formats settings.
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
   * Test filter format entity clone.
   */
  public function testFilterFormatEntityClone() {
    $edit = [
      'name' => 'Test filter format for clone',
      'format' => 'test_filter_format_for_clone',
    ];
    $this->drupalGet("admin/config/content/formats/add");
    $this->submitForm($edit, 'Save configuration');

    $filter_formats = \Drupal::entityTypeManager()
      ->getStorage('filter_format')
      ->loadByProperties([
        'format' => $edit['format'],
      ]);
    $filter_format = reset($filter_formats);

    $edit = [
      'id' => 'test_filter_format_cloned',
      'label' => 'Test filter format cloned',
    ];
    $this->drupalGet('entity_clone/filter_format/' . $filter_format->id());
    $this->submitForm($edit, 'Clone');

    $filter_formats = \Drupal::entityTypeManager()
      ->getStorage('filter_format')
      ->loadByProperties([
        'format' => $edit['id'],
      ]);
    $filter_format = reset($filter_formats);
    $this->assertInstanceOf(FilterFormat::class, $filter_format, 'Test filter format cloned found in database.');
  }

}
