<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_access\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects the front page based on authentication state.
 *
 * - Anonymous     → /user/login
 * - Authenticated → /feed (Community feed)
 */
final class FrontPageRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
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

    if ($this->currentUser->isAuthenticated()) {
      $event->setResponse(new RedirectResponse('/feed', 302));
    }
    else {
      $event->setResponse(new RedirectResponse('/user/login', 302));
    }
  }

}
