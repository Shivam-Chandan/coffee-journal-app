<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Create a date format and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneDateFormatTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone'];

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
    'clone date_format entity',
    'administer site configuration',
  ];

  /**
   * An administrative user with permission to configure date formats settings.
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
   * Test date format entity clone.
   */
  public function testDateFormatEntityClone() {
    $edit = [
      'label' => 'Test date format for clone',
      'id' => 'test_date_format_for_clone',
      'date_format_pattern' => 'Y m d',
    ];
    $this->drupalGet("admin/config/regional/date-time/formats/add");
    $this->submitForm($edit, 'Add format');

    $date_formats = \Drupal::entityTypeManager()
      ->getStorage('date_format')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $date_format = reset($date_formats);

    $edit = [
      'id' => 'test_date_format_cloned',
      'label' => 'Test date format cloned',
    ];
    $this->drupalGet('entity_clone/date_format/' . $date_format->id());
    $this->submitForm($edit, 'Clone');

    $date_formats = \Drupal::entityTypeManager()
      ->getStorage('date_format')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $date_format = reset($date_formats);
    $this->assertInstanceOf(DateFormat::class, $date_format, 'Test date format cloned found in database.');
  }

}
