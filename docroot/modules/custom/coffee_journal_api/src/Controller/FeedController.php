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
    $feed_html = <<<HTML
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
      '#markup' => $feed_html,
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
