<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
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
    $html = <<<HTML
<nav id="cj-sidebar-nav" class="cj-sidebar-nav" role="navigation" aria-label="Main navigation">
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
</nav>

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
HTML;

    return [
      '#type' => 'markup',
      '#markup' => $html,
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

}
