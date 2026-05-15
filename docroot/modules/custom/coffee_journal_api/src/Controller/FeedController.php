<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the global feed page.
 */
final class FeedController extends ControllerBase {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
    );
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
      '#theme' => 'page',
      '#content' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'cj-feed-container'],
        '#attached' => [
          'drupalSettings' => [
            'coffeeJournal' => [
              'feedApiEndpoint' => '/jsonapi/node/brew_recipe',
              'coffeeBeanApiEndpoint' => '/jsonapi/node/coffee_bean',
            ],
          ],
        ],
        'sidebar_nav' => [
          '#theme' => 'coffee_journal_sidebar_nav',
          '#current_page' => 'feed',
        ],
        'feed' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'cj-global-feed',
            'class' => ['feed-container'],
          ],
        ],
      ],
    ];
  }

}
