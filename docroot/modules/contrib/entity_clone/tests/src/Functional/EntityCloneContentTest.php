<?php

namespace Drupal\Tests\entity_clone\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\node\Functional\NodeTestBase;

// Workaround to support tests against Drupal 8, 9, 10 and 11.
if (!trait_exists(EntityReferenceFieldCreationTrait::class)) {
  class_alias('\Drupal\Tests\field\Traits\EntityReferenceTestTrait', EntityReferenceFieldCreationTrait::class);
}

/**
 * Create a content and test a clone.
 *
 * @group entity_clone
 */
class EntityCloneContentTest extends NodeTestBase {

  use EntityReferenceFieldCreationTrait;
  use StringTranslationTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'entity_clone',
    'block',
    'node',
    'datetime',
    'taxonomy',
    'content_translation',
    'language',
  ];

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
    'bypass node access',
    'administer nodes',
    'clone node entity',
  ];

  /**
   * A user with permission to bypass content access checks.
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

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('es')->save();
    \Drupal::service('content_translation.manager')->setEnabled('node', 'page', TRUE);
  }

  /**
   * Test content entity clone.
   */
  public function testContentEntityClone() {
    $node_title = $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'page',
      'title' => $node_title,
    ]);
    $node->save();
    $this->drupalGet('entity_clone/node/' . $node->id());

    $this->submitForm([], 'Clone');

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'title' => $node_title . ' - Cloned',
      ]);
    $node = reset($nodes);
    $this->assertInstanceOf(Node::class, $node, 'Test node cloned found in database.');
  }

  /**
   * Test content reference config entity.
   */
  public function testContentReferenceConfigEntity() {
    $this->createEntityReferenceField('node', 'page', 'config_field_reference', 'Config field reference', 'taxonomy_vocabulary');

    $node_title = $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'page',
      'title' => $node_title,
      'config_field_reference' => 'tags',
    ]);
    $node->save();

    $this->drupalGet('entity_clone/node/' . $node->id());
    $this->assertSession()->elementNotExists('css', '#edit-recursive-nodepageconfig-field-reference');
  }

  /**
   * Test the cloned entity's created and changed dates.
   *
   * For entities that support these kinds of dates, both are reset to the
   * current time.
   */
  public function testCreatedAndChangedDate() {
    // Create the original node.
    $original_node_creation_date = new \DateTimeImmutable('1 year 1 month 1 day ago');
    $translation_creation_date = new \DateTimeImmutable('1 month 1 day ago');
    $original_node = Node::create([
      'type' => 'page',
      'title' => 'Test',
      'created' => $original_node_creation_date->getTimestamp(),
      'changed' => $original_node_creation_date->getTimestamp(),
    ]);
    $original_node->addTranslation('fr', $original_node->toArray());
    // The translation was created and updated later.
    $translation = $original_node->getTranslation('fr');
    $translation->setCreatedTime($translation_creation_date->getTimestamp());
    $translation->setChangedTime($translation_creation_date->getTimestamp());
    $original_node->save();

    $original_node = Node::load($original_node->id());
    $this->assertEquals($original_node_creation_date->getTimestamp(), $original_node->getCreatedTime());
    $this->assertEquals($original_node_creation_date->getTimestamp(), $original_node->getChangedTime());
    $this->assertEquals($translation_creation_date->getTimestamp(), $original_node->getTranslation('fr')->getCreatedTime());
    $this->assertEquals($translation_creation_date->getTimestamp(), $original_node->getTranslation('fr')->getChangedTime());
    $this->drupalGet('entity_clone/node/' . $original_node->id());

    // Clone the node.
    $this->submitForm([], 'Clone');

    // Find the cloned node.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'title' => sprintf('%s - Cloned', $original_node->label()),
      ]);
    $this->assertGreaterThanOrEqual(1, count($nodes));
    /** @var \Drupal\node\NodeInterface $cloned_node */
    $cloned_node = reset($nodes);

    // Validate the cloned node's created time is more recent than the original
    // node.
    $this->assertNotEquals($original_node->getCreatedTime(), $cloned_node->getCreatedTime());
    $this->assertGreaterThanOrEqual($original_node->getCreatedTime(), $cloned_node->getCreatedTime());

    // Assert the changed time is equal to the newly created time since we
    // cannot have a changed date before it.
    $this->assertEquals($cloned_node->getCreatedTime(), $cloned_node->getChangedTime());

    // Validate the translation created and updated dates.
    $this->assertTrue($cloned_node->hasTranslation('fr'));
    $translation = $cloned_node->getTranslation('fr');
    // The created and updated times should be the same between the original
    // and the translation as both should be reset.
    $this->assertEquals($cloned_node->getCreatedTime(), $translation->getCreatedTime());
    $this->assertEquals($cloned_node->getChangedTime(), $translation->getChangedTime());
  }

  /**
   * Test entity translations cloning.
   *
   * Test the cloning of nodes with translations in four different ways and
   * assert that translations are either kept or removed according to the form
   * values. Also, assert that the default language is changed when cloning
   * the current translation into a new node discarding all others.
   *
   * 1. Clone node keeping all translations
   * 2. Clone node keeping only one translation (default plus one)
   * 3. Clone current translation only (discarding previous default)
   * 4. Clone current translation keeping all others (not changing default)
   */
  public function testContentEntityTranslationsClone() {
    // 1. Clone node keeping all translations.
    $node_title = $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'page',
      'title' => $node_title,
    ]);
    $node->save();

    $node->addTranslation('fr', $node->toArray());
    $node->addTranslation('es', $node->toArray());
    $node->save();

    $this->drupalGet('entity_clone/node/' . $node->id());
    $this->submitForm(['edit-all-translations' => TRUE], 'Clone');

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'title' => $node_title . ' - Cloned',
      ]);
    $node = reset($nodes);
    $this->assertInstanceOf(Node::class, $node, 'Test node cloned found in database.');
    // Only two translations should be present.
    $this->assertCount(3, $node->getTranslationLanguages());
    $translation = $node->getTranslation('fr');
    // The French translation should not be the default one.
    $this->assertFalse($translation->isDefaultTranslation());

    $translation = $node->getTranslation('en');
    // The French translation should not be the default one.
    $this->assertTrue($translation->isDefaultTranslation());

    // 2. Clone node keeping only one translation (default plus one)
    $node_title = $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'page',
      'title' => $node_title,
    ]);
    $node->save();

    $node->addTranslation('fr', $node->toArray());
    $node->addTranslation('es', $node->toArray());
    $node->save();

    $this->drupalGet('entity_clone/node/' . $node->id());
    $this->submitForm([
      'edit-all-translations' => FALSE,
      'edit-es' => FALSE,
      'edit-fr' => TRUE,
    ], 'Clone');

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'title' => $node_title . ' - Cloned',
      ]);
    $node = reset($nodes);
    $this->assertInstanceOf(Node::class, $node, 'Test node cloned found in database.');
    // Only two translations should be present.
    $this->assertCount(2, $node->getTranslationLanguages());
    $translation = $node->getTranslation('fr');
    // The French translation should not be the default one.
    $this->assertFalse($translation->isDefaultTranslation());

    // 3. Clone current translation only (discarding previous default)
    $node_title = $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'page',
      'title' => $node_title,
    ]);
    $node->save();
    $node->addTranslation('fr', $node->toArray());
    $node->save();
    $this->drupalGet('fr/entity_clone/node/' . $node->id());
    $this->submitForm(['edit-current-translation' => TRUE], 'Clone');

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'title' => $node_title . ' - Cloned',
      ]);
    $node = reset($nodes);
    $this->assertInstanceOf(Node::class, $node, 'Test node cloned found in database.');
    // Only one translation should be present.
    $this->assertCount(1, $node->getTranslationLanguages());
    // The translation should be the default one.
    $this->assertTrue($node->getTranslation('fr')->isDefaultTranslation());

    // 4. Clone current translation only (discarding previous default)
    $node_title = $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'page',
      'title' => $node_title,
    ]);
    $node->save();
    $node->addTranslation('fr', $node->toArray());
    $node->addTranslation('es', $node->toArray());
    $node->save();
    $this->drupalGet('fr/entity_clone/node/' . $node->id());
    $this->submitForm(['edit-current-translation' => FALSE], 'Clone');

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'title' => $node_title . ' - Cloned',
      ]);
    $node = reset($nodes);
    $this->assertInstanceOf(Node::class, $node, 'Test node cloned found in database.');
    // All translations should still be present.
    $this->assertCount(3, $node->getTranslationLanguages());
    // The default translation should remain unchanged.
    $this->assertFalse($node->getTranslation('fr')->isDefaultTranslation());
    $this->assertTrue($node->getTranslation('en')->isDefaultTranslation());
  }

}
