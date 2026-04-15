<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_access\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects the front page based on authentication state.
 *
 * - Anonymous  → /user/login
 * - Authenticated → /my-coffees
 *
 * This keeps the site.page.front setting at /node (the Drupal default)
 * and intercepts requests to '/' at the kernel.request level instead,
 * so no contrib redirect module is required.
 */
final class FrontPageRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 30 — fires after routing but before controller resolution.
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Redirect the front page (path '/') to the appropriate destination.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only intercept the exact front-page path.
    if ($request->getPathInfo() !== '/') {
      return;
    }

    // Sub-requests (AJAX, ESI) should not redirect.
    if (!$event->isMainRequest()) {
      return;
    }

    if ($this->currentUser->isAuthenticated()) {
      $destination = Url::fromRoute('view.coffee_journal.page_1')->toString();
    }
    else {
      $destination = Url::fromRoute('user.login')->toString();
    }

    $response = new TrustedRedirectResponse($destination, 302);
    // Vary the cache by user authentication state so both paths are cached separately.
    $response->getCacheableMetadata()
      ->addCacheContexts(['user.roles:authenticated']);

    $event->setResponse($response);
  }

}
