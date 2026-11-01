<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\Core\Site\Settings;

/**
 * Environment-specific server/client settings; never exported as Drupal config.
 */
final class OAuthSettings {

  public const SCOPES = ['beans:read', 'beans:create'];

  public function get(string $key, mixed $default = ''): mixed {
    return Settings::get('coffee_journal_oauth', [])[$key] ?? $default;
  }

  public function enabled(): bool {
    return $this->get('enabled', FALSE) === TRUE;
  }

  public function discoveryEnabled(): bool {
    return $this->enabled() || $this->get('discovery_enabled', FALSE) === TRUE;
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
    return 'Bearer resource_metadata="' . $this->metadataUrl() . '", error="invalid_token", error_description="Connect your Coffee Journal account to continue"';
  }

  public function clientId(): string {
    $id = $this->get('client_id');
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9._-]{8,128}$/D', $id)) {
      throw new \LogicException('Configure a predefined ChatGPT OAuth client ID.');
    }
    return $id;
  }

  public function redirectUri(): string {
    $uri = $this->get('redirect_uri');
    $parts = is_string($uri) ? parse_url($uri) : FALSE;
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'chatgpt.com'
      || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || str_contains($uri, '*')) {
      throw new \LogicException('Configure the exact HTTPS ChatGPT callback from the plugin management page.');
    }
    return $uri;
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
