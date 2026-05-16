<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the global feed page.
 */
final class FeedController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Renders the global community feed page.
   */
  public function globalFeed() {
    // Fetch public brew recipes for display
    $recipeHtml = $this->getRecipesHtml();

    return [
      '#type' => 'markup',
      '#markup' => <<<HTML
<div class="cj-page-inner">
  <section class="cj-feed-section">
    <h1 class="cj-feed-title">Community Feed</h1>
    <div id="cj-feed-loading" class="cj-feed-loading" aria-hidden="true">
      <span class="cj-loading-spinner"></span>
      <p>Loading recipes…</p>
    </div>
    <div id="cj-global-feed" class="cj-global-feed">
      {$recipeHtml}
    </div>
    <div class="cj-feed-actions">
      <button id="cj-load-more" class="cj-load-more-btn" aria-label="Load more recipes">Load More</button>
    </div>
  </section>
</div>
HTML,
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'tags' => ['node:brew_recipe'],
        'max-age' => 300,
      ],
      '#attached' => [
        'library' => [
          'coffee_journal/app',
        ],
        'drupalSettings' => [
          'coffeeJournal' => [
            'feedApiEndpoint' => '/jsonapi/node/brew_recipe',
            'coffeeBeanApiEndpoint' => '/jsonapi/node/coffee_bean',
          ],
        ],
      ],
    ];
  }

  /**
   * Fetch and render public brew recipes as HTML.
   */
  private function getRecipesHtml(): string {
    try {
      $currentUser = \Drupal::currentUser();
      
      $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
      $query = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'brew_recipe')
        ->condition('field_is_public', 1)
        ->sort('created', 'DESC')
        ->range(0, 20);
      
      $nids = $query->execute();
      
      if (empty($nids)) {
        $msg = 'No recipes found for user ' . $currentUser->id() . ' (' . $currentUser->getAccountName() . ')';
        \Drupal::logger('coffee_journal_api')->info($msg);
        return '<p class="cj-feed-empty">No recipes shared yet. Be the first to share your brew method!</p>';
      }
      
      $nodes = $nodeStorage->loadMultiple(array_values($nids));
      $html = '';
      
      foreach ($nodes as $node) {
        $html .= $this->renderRecipeCard($node);
      }
      
      \Drupal::logger('coffee_journal_api')->info('Rendered ' . count($nodes) . ' recipes for user ' . $currentUser->getAccountName());
      return $html;
    } catch (\Exception $e) {
      \Drupal::logger('coffee_journal_api')->error('Error loading recipes: ' . $e->getMessage());
      return '<p class="cj-feed-error">Error loading recipes</p>';
    }
  }

  /**
   * Render a single recipe card HTML.
   */
  private function renderRecipeCard($node): string {
    $title = $node->label();
    $nid = $node->id();
    $method = $node->field_brew_method?->value ?? '';
    $coffeeWeight = $node->field_coffee_weight?->value ?? '';
    $waterWeight = $node->field_water_weight?->value ?? '';
    $notes = $node->field_community_notes?->value ?? '';
    
    $ratio = '';
    if ($coffeeWeight && $waterWeight) {
      $ratio = number_format($waterWeight / $coffeeWeight, 2);
    }
    
    $html = '<article class="cj-recipe-card" data-recipe-id="' . htmlspecialchars($nid) . '">';
    $html .= '<div class="cj-recipe-card__header">';
    $html .= '<h3 class="cj-recipe-card__title"><a href="/node/' . htmlspecialchars($nid) . '">' . htmlspecialchars($title) . '</a></h3>';
    $html .= '</div>';
    $html .= '<div class="cj-recipe-card__body"><div class="cj-recipe-info">';
    
    if ($method) {
      $html .= '<div class="cj-recipe-field"><strong class="cj-recipe-label">Method:</strong> <span class="cj-recipe-value">' . htmlspecialchars($method) . '</span></div>';
    }
    
    if ($coffeeWeight) {
      $html .= '<div class="cj-recipe-field"><strong class="cj-recipe-label">Coffee:</strong> <span class="cj-recipe-value">' . htmlspecialchars($coffeeWeight) . 'g</span></div>';
    }
    
    if ($waterWeight) {
      $html .= '<div class="cj-recipe-field"><strong class="cj-recipe-label">Water:</strong> <span class="cj-recipe-value">' . htmlspecialchars($waterWeight) . 'g</span></div>';
    }
    
    if ($ratio) {
      $html .= '<div class="cj-recipe-field"><strong class="cj-recipe-label">Ratio:</strong> <span class="cj-recipe-value">1:' . htmlspecialchars($ratio) . '</span></div>';
    }
    
    if ($notes) {
      $html .= '<div class="cj-recipe-field"><strong class="cj-recipe-label">Notes:</strong> <span class="cj-recipe-value">' . htmlspecialchars($notes) . '</span></div>';
    }
    
    $html .= '</div><div class="cj-recipe-card__meta"><p class="cj-recipe-comments">💬 0</p></div></div>';
    $html .= '<div class="cj-recipe-card__footer"><a href="/node/' . htmlspecialchars($nid) . '" class="cj-recipe-card__link">View Recipe</a></div>';
    $html .= '</article>';
    
    return $html;
  }

  /**
   * Build the sidebar navigation render element.
   */
  private function buildSidebarNav() {
    return [
      '#markup' => <<<NAV
<button id="cj-nav-toggle" class="cj-nav-toggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="cj-nav-menu">
  <span aria-hidden="true">☰</span>
</button>
<div id="cj-nav-menu" class="cj-nav-menu" aria-hidden="true">
  <ul class="cj-nav-list">
    <li class="cj-nav-item active">
      <a href="/feed" class="cj-nav-link" aria-current="page">
        <span class="cj-nav-icon" aria-hidden="true">☕</span>
        <span class="cj-nav-label">Global Feed</span>
      </a>
    </li>
    <li class="cj-nav-item">
      <a href="/my-coffees" class="cj-nav-link">
        <span class="cj-nav-icon" aria-hidden="true">📔</span>
        <span class="cj-nav-label">My Coffees</span>
      </a>
    </li>
    <li class="cj-nav-item">
      <a href="/user/profile" class="cj-nav-link">
        <span class="cj-nav-icon" aria-hidden="true">👤</span>
        <span class="cj-nav-label">Profile</span>
      </a>
    </li>
  </ul>
</div>
NAV,
    ];
  }

  /**
   * Build the main content area render element.
   */
  private function buildMainContent() {
    return [
      '#markup' => <<<MAIN
<header id="cj-topbar" role="banner">
  <a href="/" class="cj-brand" aria-label="Coffee Journal - Home">
    <span class="cj-brand-icon" aria-hidden="true">☕</span>
    <span>Coffee Journal</span>
  </a>
  <div class="cj-topbar-right">
    <button id="cj-theme-toggle" aria-label="Toggle colour theme" title="Toggle colour theme">
      <span aria-hidden="true">🌙</span>
    </button>
  </div>
</header>
<main id="cj-main" role="main">
  <a id="main-content" tabindex="-1"></a>
  <div class="cj-page-inner">
    <section class="cj-feed-section">
      <h1 class="cj-feed-title">Community Feed</h1>
      <div id="cj-feed-loading" class="cj-feed-loading" aria-hidden="true">
        <span class="cj-loading-spinner"></span>
        <p>Loading recipes…</p>
      </div>
      <div id="cj-global-feed" class="cj-global-feed"></div>
      <div class="cj-feed-actions">
        <button id="cj-load-more" class="cj-load-more-btn" aria-label="Load more recipes">Load More</button>
      </div>
    </section>
  </div>
</main>
MAIN,
    ];
  }

}
