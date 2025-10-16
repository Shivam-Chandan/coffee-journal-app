<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Tests\node\Functional\NodeTestBase;

/**
 * Tests the "cloneable entities" configuration.
 *
 * @group entity_clone
 */
class EntityCloneCloneableEntitiesConfigTest extends NodeTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['entity_clone', 'paragraphs_demo'];

  /**
   * Theme to enable by default.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Profile to install.
   *
   * @var string
   */
  protected $profile = 'standard';

  /**
   * An administrative user with permission to configure cloneable entities.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Permissions to grant admin user.
   *
   * @var array
   */
  protected $permissions = [
    'access content',
    'administer entity clone',
    'clone node entity',
    'clone paragraph entity',
    'create paragraphed_content_demo content',
    'view unpublished paragraphs',
  ];

  /**
   * Sets the test up.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser($this->permissions);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Test Cloneable entities config form.
   */
  public function testCloneableEntitiesConfigForm() {
    $this->drupalGet('admin/config/system/entity-clone/cloneable-entities');

    // Assert all entity types are enabled by default.
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $entity_type) {
      $this->assertSession()->checkboxChecked("cloneable_entities[table][{$entity_type->id()}][value]");
    }

    // Uncheck node and paragraphs to be cloned.
    $page = $this->getSession()->getPage();
    $page->uncheckField('cloneable_entities[table][node][value]');
    $page->uncheckField('cloneable_entities[table][paragraph][value]');
    $page->uncheckField('cloneable_entities[table][paragraphs_library_item][value]');
    $page->pressButton('Save configuration');

    // Enable file module and assert it is enabled by default to be cloneable.
    \Drupal::service('module_installer')->install(['file']);
    $this->getSession()->reload();
    $this->assertSession()->checkboxChecked('cloneable_entities[table][file][value]');

    // Assert previously unchecked items remained unchecked.
    $this->assertSession()->checkboxNotChecked('cloneable_entities[table][node][value]');
    $this->assertSession()->checkboxNotChecked('cloneable_entities[table][paragraph][value]');
    $this->assertSession()->checkboxNotChecked('cloneable_entities[table][paragraphs_library_item][value]');

    // Use node from paragraphs_demo_install().
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node_title = 'Welcome to the Paragraphs Demo module!';
    $nodes = $node_storage->loadByProperties([
      'title' => $node_title,
    ]);
    $node = reset($nodes);

    // Visit the node and assert the local task is not in place.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextNotContains('Clone');

    // Now enable nodes to be cloned.
    $this->drupalGet('admin/config/system/entity-clone/cloneable-entities');
    $page->checkField('cloneable_entities[table][node][value]');
    $page->pressButton('Save configuration');

    // Visit the node and assert the local task is in place.
    $this->drupalGet($node->toUrl());
    $page->clickLink('Clone');

    // Assert the clone settings form doesn't have the checkboxes because
    // paragraphs are not enabled.
    $this->assertSession()->elementNotExists('css', '#edit-recursive-nodeparagraphed-content-demofield-paragraphs-demo');

    // Assert the amount of nodes and paragraphs in the system.
    $nodes = $node_storage->loadMultiple();
    $this->assertCount(1, $nodes);
    $paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $paragraphs = $paragraph_storage->loadMultiple();
    $this->assertCount(7, $paragraphs);

    $page->pressButton('Clone');

    // Assert the amount of nodes and paragraphs in the system.
    $nodes = $node_storage->loadMultiple();
    $this->assertCount(2, $nodes);
    $paragraphs = $paragraph_storage->loadMultiple();
    $this->assertCount(7, $paragraphs);

    // Now enable paragraphs to be cloned.
    $this->drupalGet('admin/config/system/entity-clone/cloneable-entities');
    $page->checkField('cloneable_entities[table][paragraph][value]');
    $page->pressButton('Save configuration');

    $this->drupalGet('admin/config/system/entity-clone');

    // Delete the cloned node and re-do the cloning process.
    $cloned_node = $node_storage->loadByProperties([
      'title' => $node_title . ' - Cloned',
    ]);
    $cloned_node = reset($cloned_node);
    $cloned_node->delete();

    $this->drupalGet($node->toUrl());
    $page->clickLink('Clone');

    // Checkboxes for paragraphs are visible.
    $this->assertSession()->elementExists('css', '#edit-recursive-nodeparagraphed-content-demofield-paragraphs-demo');
    $page->checkField('recursive[node.paragraphed_content_demo.field_paragraphs_demo][references][1][clone]');
    $page->checkField('recursive[node.paragraphed_content_demo.field_paragraphs_demo][references][2][clone]');
    $page->checkField('recursive[node.paragraphed_content_demo.field_paragraphs_demo][references][3][clone]');
    $page->checkField('recursive[node.paragraphed_content_demo.field_paragraphs_demo][references][5][clone]');
    $page->checkField('recursive[node.paragraphed_content_demo.field_paragraphs_demo][references][5][children][recursive][paragraph.nested_paragraph.field_paragraphs_demo][references][4][clone]');
    $page->pressButton('Clone');

    // Assert the amount of nodes and paragraphs in the system.
    $nodes = $node_storage->loadMultiple();
    $this->assertCount(2, $nodes);

    // Assert we have 12 paragraphs we marked 5 items to clone in the form.
    $paragraphs = $paragraph_storage->loadMultiple();
    $this->assertCount(12, $paragraphs);
  }

}
