<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\Core\Site\Settings;

/**
 * Environment-specific server/client settings; never exported as Drupal config.
 */
final class OAuthSettings {

  public const SCOPES = ['beans:read', 'beans:create'];

  /**
   * Documented callback hosts per provider. Exact URIs stay runtime settings.
   */
  private const CALLBACK_HOSTS = ['chatgpt' => 'chatgpt.com', 'claude' => 'claude.ai'];

  /**
   * Allowlisted Client ID Metadata Document URLs, keyed by provider.
   *
   * A client may present one of these HTTPS URLs as its client_id instead of a
   * pre-registered string. Only these exact URLs are ever fetched.
   */
  private const METADATA_DOCUMENTS = [
    'chatgpt' => 'https://chatgpt.com/oauth/client.json',
  ];

  public function get(string $key, mixed $default = ''): mixed {
    return Settings::get('coffee_journal_oauth', [])[$key] ?? $default;
  }

  public function enabled(): bool {
    return $this->get('enabled', FALSE) === TRUE
      && ($this->clients() !== [] || $this->metadataDocuments() !== []);
  }

  public function issuer(): string {
    $issuer = $this->get('issuer');
    if (!is_string($issuer) || !preg_match('#^https://[a-z0-9.-]+(?::[0-9]+)?$#Di', $issuer)) {
      throw new \LogicException('Configure a canonical HTTPS OAuth issuer origin.');
    }
    return $issuer;
  }

  public function resource(): string {
    return $this->issuer() . '/api/integrations/v1/mcp';
  }

  public function metadataUrl(): string {
    return $this->issuer() . '/.well-known/oauth-protected-resource';
  }

  public function challenge(): string {
    return 'Bearer resource_metadata="' . $this->metadataUrl() . '", scope="'
      . implode(' ', self::SCOPES)
      . '", error="invalid_token", error_description="Connect your Coffee Journal account to continue"';
  }

  /**
   * Returns every configured provider client keyed by its client ID.
   *
   * Each provider is registered independently so one client's credential or
   * callback can never authorize another provider's connection.
   */
  public function clients(): array {
    $clients = [];
    foreach (array_keys(self::CALLBACK_HOSTS) as $provider) {
      $id = $this->get($provider . '_client_id');
      $redirects = $this->redirects($provider);
      if (is_string($id) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $id) && $redirects) {
        $clients[$id] = [
          'provider' => $provider,
          'redirects' => $redirects,
          'secret' => (string) $this->get($provider . '_client_secret'),
        ];
      }
    }
    return $clients;
  }

  /**
   * Returns allowlisted metadata document URLs for providers with callbacks.
   */
  public function metadataDocuments(): array {
    $documents = [];
    foreach (self::METADATA_DOCUMENTS as $provider => $url) {
      if ($this->redirects($provider)) {
        $documents[$provider] = $url;
      }
    }
    return $documents;
  }

  public function client(string $clientId): ?array {
    return $this->clients()[$clientId] ?? NULL;
  }

  /**
   * Validates the provider's exact registered callbacks from its own settings.
   */
  public function redirects(string $provider): array {
    $configured = $this->get($provider . '_redirect_uris');
    $host = self::CALLBACK_HOSTS[$provider] ?? NULL;
    if (!$host || !is_string($configured) || $configured === '') {
      return [];
    }
    $valid = [];
    foreach (preg_split('/\s+/', trim($configured)) as $uri) {
      $parts = parse_url($uri);
      if ($parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host
        && !isset($parts['user'], $parts['pass']) && !isset($parts['fragment']) && !str_contains($uri, '*')) {
        $valid[] = $uri;
      }
    }
    return array_values(array_unique($valid));
  }

  public function keyPath(string $type): string {
    $path = $this->get($type . '_key');
    $real = is_string($path) ? realpath($path) : FALSE;
    if (!$real || !is_readable($real) || str_starts_with($real, realpath(DRUPAL_ROOT) . DIRECTORY_SEPARATOR)) {
      throw new \LogicException('OAuth signing keys must be readable files outside the web root.');
    }
    return $real;
  }
}
