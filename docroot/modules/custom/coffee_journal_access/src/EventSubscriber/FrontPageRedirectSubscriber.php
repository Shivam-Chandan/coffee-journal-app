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
 * - Authenticated → / (Community feed, no redirect)
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
    // Priority 100 — fires before page_cache (which runs at ~27) so the
    // redirect response is set before the cache layer can serve a stale 403.
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
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

    // Anonymous users are handled by the route requirement (_user_is_logged_in).
    // No additional redirect logic needed here.
  }

}
