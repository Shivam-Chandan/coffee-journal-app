<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Reserves attempts before paid calls, independently per user and client IP.
 */
final class PaidApiLimiter {

  private const DEFAULTS = [
    'scrape' => ['user_minute' => 5, 'user_day' => 50, 'ip_minute' => 25, 'ip_day' => 250],
    'places' => ['user_minute' => 20, 'user_day' => 200, 'ip_minute' => 100, 'ip_day' => 1000],
  ];

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Returns a rejection, or NULL after reserving this attempt in every window.
   */
  public function reserve(string $endpoint, int $uid, string $ip): ?JsonResponse {
    $limits = $this->configFactory->get('coffee_journal_api.settings')->get('limits.' . $endpoint) ?? [];
    $rules = [];
    $keys = [];
    foreach (['ip' => hash('sha256', $ip), 'user' => (string) $uid] as $scope => $identifier) {
      $keys[] = 'coffee_journal_api.' . $endpoint . '.' . $scope . '.' . $identifier;
      foreach (['minute' => 60, 'day' => 86400] as $period => $window) {
        $key = $scope . '_' . $period;
        $limit = max(1, (int) ($limits[$key] ?? self::DEFAULTS[$endpoint][$key]));
        $rules[] = ['coffee_journal_api.' . $endpoint . '.' . $key, $limit, $window, $identifier];
      }
    }
    // Flood's check/register pair is not atomic; serialize shared identities.
    $held = [];
    try {
      foreach ($keys as $key) {
        if (!$this->lock->acquire($key, 10)) {
          return $this->rejected(1);
        }
        $held[] = $key;
      }
      foreach ($rules as [$event, $limit, $window, $identifier]) {
        if (!$this->flood->isAllowed($event, $limit, $window, $identifier)) {
          return $this->rejected($window);
        }
      }
      foreach ($rules as [$event, $limit, $window, $identifier]) {
        $this->flood->register($event, $window, $identifier);
      }
      return NULL;
    }
    finally {
      foreach (array_reverse($held) as $key) {
        $this->lock->release($key);
      }
    }
  }

  private function rejected(int $retryAfter): JsonResponse {
    return new JsonResponse([
      'error' => 'Request limit reached. Please wait before trying again.',
      'code' => 'rate_limit_exceeded',
      'retry_after' => $retryAfter,
    ], 429, ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'private, no-store']);
  }

}
