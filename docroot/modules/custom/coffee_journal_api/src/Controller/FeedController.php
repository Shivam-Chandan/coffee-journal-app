<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
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
   *
   * This page displays publicly shared brew recipes from the community.
   * The actual data fetching and rendering is handled by the cjGlobalFeed
   * vanilla JS Drupal behavior via JSON:API queries.
   */
  public function globalFeed() {
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'cj-feed-container',
        'class' => ['cj-feed-page'],
      ],
      '#attached' => [
        'drupalSettings' => [
          'coffeeJournal' => [
            'feedApiEndpoint' => '/jsonapi/node/brew_recipe',
            'coffeeBeanApiEndpoint' => '/jsonapi/node/coffee_bean',
          ],
        ],
      ],
      'sidebar' => [
        '#type' => 'markup',
        '#markup' => '<nav id="cj-sidebar-nav" class="cj-sidebar-nav" role="navigation" aria-label="Main navigation">
          <button id="cj-nav-toggle" class="cj-nav-toggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="cj-nav-menu">
            <span aria-hidden="true">☰</span>
          </button>
          <div id="cj-nav-menu" class="cj-nav-menu" aria-hidden="true">
            <ul class="cj-nav-list">
              <li class="cj-nav-item active">
                <a href="/" class="cj-nav-link" aria-current="page">
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
        </nav>',
      ],
      'feed' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'cj-global-feed',
          'class' => ['feed-container'],
        ],
        '#markup' => '<h1 class="cj-feed-title">Community Feed</h1>
          <div id="cj-feed-loading" class="cj-feed-loading" aria-hidden="true">
            <span class="cj-loading-spinner"></span>
            <p>Loading recipes…</p>
          </div>',
      ],
      'load_more' => [
        '#type' => 'markup',
        '#markup' => '<div class="cj-feed-actions">
          <button id="cj-load-more" class="cj-load-more-btn" aria-label="Load more recipes">Load More</button>
        </div>',
      ],
    ];
  }

}
