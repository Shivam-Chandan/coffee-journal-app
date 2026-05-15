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
    // At this point, the route requirement _user_is_logged_in ensures the user
    // is authenticated. If not, Drupal's permission system will redirect to login.

    // Return the content to be rendered by the page template
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'cj-feed-page-wrapper',
      ],
      'sidebar_nav' => [
        '#theme' => 'coffee_journal_sidebar_nav',
        '#current_page' => 'feed',
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['cj-main-content'],
        ],
        'feed_section' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['cj-feed-section'],
          ],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h1',
            '#attributes' => ['class' => ['cj-feed-title']],
            '#value' => 'Community Feed',
          ],
          'loading' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'id' => 'cj-feed-loading',
              'class' => ['cj-feed-loading'],
              'aria-hidden' => 'true',
            ],
            '#value' => '<span class="cj-loading-spinner"></span><p>Loading recipes…</p>',
          ],
          'feed' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'id' => 'cj-global-feed',
              'class' => ['cj-global-feed'],
            ],
          ],
          'load_more' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'class' => ['cj-feed-actions'],
            ],
            '#value' => '<button id="cj-load-more" class="cj-load-more-btn" aria-label="Load more recipes">Load More</button>',
          ],
        ],
      ],
      '#attached' => [
        'drupalSettings' => [
          'coffeeJournal' => [
            'feedApiEndpoint' => '/jsonapi/node/brew_recipe',
            'coffeeBeanApiEndpoint' => '/jsonapi/node/coffee_bean',
          ],
        ],
      ],
    ];

    return $build;
  }

}
