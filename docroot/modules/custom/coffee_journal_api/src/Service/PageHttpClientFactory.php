<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;

/**
 * Dedicated cURL transport: no fallback handler, proxy, or site middleware.
 */
final class PageHttpClientFactory {

  public static function create(): ClientInterface {
    return new Client(['handler' => new CurlHandler()]);
  }

}
