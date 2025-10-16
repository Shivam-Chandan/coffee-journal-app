<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search\Entity\SearchPage;
use Drupal\Tests\BrowserTestBase;

/**
 * Create a search page and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneSearchPageTest extends BrowserTestBase {

  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'search', 'node'];

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
    'administer search',
    'clone search_page entity',
  ];

  /**
   * An administrative user with permission to configure search pages settings.
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
   * Test search page entity clone.
   */
  public function testSearchPageEntityClone() {
    $edit = [
      'label' => 'Test search page for clone',
      'id' => 'test_search_page_for_clone',
      'path' => 'test_search_page_for_clone_url',
    ];
    $this->drupalGet("/admin/config/search/pages/add/node_search");
    $this->submitForm($edit, 'Save');

    $search_pages = \Drupal::entityTypeManager()
      ->getStorage('search_page')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $search_page = reset($search_pages);

    $edit = [
      'id' => 'test_search_page_cloned',
      'label' => 'Test search page cloned',
    ];
    $this->drupalGet('entity_clone/search_page/' . $search_page->id());
    $this->submitForm($edit, 'Clone');

    $search_pages = \Drupal::entityTypeManager()
      ->getStorage('search_page')
      ->loadByProperties([
        'id' => $edit['id'],
      ]);
    $search_page = reset($search_pages);
    $this->assertInstanceOf(SearchPage::class, $search_page, 'Test search page cloned found in database.');
  }

}
