<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Resolves Client ID Metadata Document clients.
 *
 * ChatGPT identifies itself with an HTTPS metadata URL as its client_id rather
 * than a pre-registered string. Only exact allowlisted document URLs are
 * fetched, so this never becomes an open registration or fetching service.
 */
final class ClientMetadataDocuments {

  private const MAX_BYTES = 16384;
  private const CACHE_SECONDS = 3600;

  public function __construct(
    private readonly OAuthSettings $settings,
    private readonly ClientInterface $http,
    private readonly KeyValueExpirableFactoryInterface $keyValue,
  ) {}

  /**
   * Returns the provider whose allowlisted document URL this client ID is.
   */
  public function provider(string $clientId): ?string {
    foreach ($this->settings->metadataDocuments() as $provider => $url) {
      if (hash_equals($url, $clientId)) {
        return $provider;
      }
    }
    return NULL;
  }

  /**
   * Fetches and validates an allowlisted document, returning its redirect URIs.
   *
   * The document only narrows what its own published client may do. It can
   * never widen scopes, change the provider, or introduce another client.
   */
  public function redirects(string $clientId): array {
    $provider = $this->provider($clientId);
    if ($provider === NULL) {
      return [];
    }
    $store = $this->keyValue->get('coffee_journal_oauth_cimd');
    $key = hash('sha256', $clientId);
    $cached = $store->get($key);
    if (is_array($cached)) {
      return $cached;
    }
    try {
      $response = $this->http->request('GET', $clientId, [
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'verify' => TRUE,
        'proxy' => '',
        'connect_timeout' => 5,
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
      ]);
      if ($response->getStatusCode() !== 200) {
        return [];
      }
      $document = json_decode((string) $response->getBody()->read(self::MAX_BYTES), TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable) {
      return [];
    }
    // The document must claim exactly the client_id that was presented.
    if (!is_array($document) || ($document['client_id'] ?? NULL) !== $clientId) {
      return [];
    }
    $expected = $this->settings->redirects($provider);
    $redirects = [];
    foreach ($document['redirect_uris'] ?? [] as $uri) {
      // Honour only callbacks this deployment already registered for the
      // provider, so a changed document cannot add a new destination.
      if (is_string($uri) && in_array($uri, $expected, TRUE)) {
        $redirects[] = $uri;
      }
    }
    if (!$redirects) {
      return [];
    }
    $store->setWithExpire($key, $redirects, self::CACHE_SECONDS);
    return $redirects;
  }
}
