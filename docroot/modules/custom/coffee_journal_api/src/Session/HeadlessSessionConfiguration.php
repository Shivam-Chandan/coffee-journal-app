<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Session;

use Drupal\Core\Session\SessionConfigurationInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Allows API sessions through external rewrites without cross-domain cookies.
 */
final class HeadlessSessionConfiguration implements SessionConfigurationInterface {

  public function __construct(
    private readonly SessionConfigurationInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function hasSession(Request $request): bool {
    return $this->inner->hasSession($request);
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(Request $request): array {
    $options = $this->inner->getOptions($request);
    $path = $request->getPathInfo();
    if (str_starts_with($path, '/api/') || $path === '/session/token') {
      // Omitting Domain binds the cookie to the browser-facing origin. Keep
      // Drupal's name, Secure, HttpOnly and SameSite settings unchanged, so
      // session detection/rotation remains Drupal's responsibility.
      $options['cookie_domain'] = '';
    }
    return $options;
  }

}
