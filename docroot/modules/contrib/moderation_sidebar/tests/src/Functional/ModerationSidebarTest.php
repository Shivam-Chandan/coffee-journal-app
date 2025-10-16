<?php

namespace Drupal\Tests\moderation_sidebar\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;

/**
 * Tests basic behaviour of Moderation Sidebar using a test entity.
 *
 * @group moderation_sidebar
 */
class ModerationSidebarTest extends BrowserTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'moderation_sidebar',
    'toolbar',
    'content_moderation',
    'node',
    'workflows',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $workflow = $this->createEditorialWorkflow();

    $this->drupalCreateContentType(['type' => 'article']);

    $this->drupalLogin($this->createUser([
      'access toolbar',
      'create article content',
      'use ' . $workflow->id() . ' transition create_new_draft',
      'use ' . $workflow->id() . ' transition archive',
      'use ' . $workflow->id() . ' transition publish',
      'use moderation sidebar',
    ]));
  }

  /**
   * Test toolbar item appears.
   */
  public function testToolbarItem(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test title',
      'moderation_state' => 'test2',
    ]);
    $node->save();
    $this->drupalGet($node->toUrl('canonical', ['absolute' => TRUE])->toString());

    // Make sure the button is where we expect it.
    $toolbarItem = $this->assertSession()->elementExists('css', '.moderation-sidebar-toolbar-tab a');
    // Make sure the button has the right attributes.
    $url = Url::fromRoute('moderation_sidebar.sidebar_latest', [
      'entity_type' => $node->getEntityTypeId(),
      'entity' => $node->id(),
    ]);
    $this->assertEquals($url->toString(), $toolbarItem->getAttribute('href'));
    $this->assertEquals('Tasks', $toolbarItem->getText());
  }

  /**
   * Test preview with moderation sidebar.
   */
  public function testPreview(): void {
    $title_key = 'title[0][value]';

    // Create an english node with an english menu.
    $this->drupalGet('/node/add/article');
    $edit = [
      $title_key => $this->randomMachineName(),
    ];
    $this->drupalGet('node/add/article');
    $this->submitForm($edit, 'Preview');

    // Check that the preview is displaying the title, body and term.
    $expected_title = $edit[$title_key] . ' | Drupal';
    $this->assertSession()->titleEquals($expected_title);
    $this->assertSession()->linkExists('Back to content editing');
    // Check that the moderation sidebar is not visible on node preview.
    $this->assertSession()->elementNotExists('css', '.moderation-sidebar-toolbar-tab');
    // Check that the moderation sidebar is visible after the node is saved.
    $this->clickLink('Back to content editing');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->elementExists('css', '.moderation-sidebar-toolbar-tab a');
  }

}
