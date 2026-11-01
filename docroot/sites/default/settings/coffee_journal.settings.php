<?php

/**
 * @file
 * Coffee Journal application settings.
 *
 * Injects environment-specific secrets into Drupal config at runtime.
 * Secrets are stored as Acquia environment variables — never in code.
 *
 * Set these in the Acquia Cloud UI (Configuration > Variables) for each env:
 *   - GOOGLE_CLIENT_ID
 *   - GOOGLE_CLIENT_SECRET
 *   - GEMINI_API_KEY
 *   - GOOGLE_PLACES_API_KEY
 *   - DRUPAL_HASH_SALT (unless Acquia platform secret settings supply the salt;
 *     consumed early in settings.php, before the recommended settings loader)
 */

// ── Google OAuth (Social Auth Google) ──────────────────────────────────────
// Maps Acquia env vars to the social_auth_google config object.
if (getenv('GOOGLE_CLIENT_ID')) {
  $config['social_auth_google.settings']['client_id'] = getenv('GOOGLE_CLIENT_ID');
}
if (getenv('GOOGLE_CLIENT_SECRET')) {
  $config['social_auth_google.settings']['client_secret'] = getenv('GOOGLE_CLIENT_SECRET');
}

// ── Trusted host patterns ───────────────────────────────────────────────────
// Acquia sets SERVER_NAME; add any custom domains here.
$settings['trusted_host_patterns'][] = '^.+\.acquia-sites\.com$';
$settings['trusted_host_patterns'][] = '^eeschandan1(dev|test)?\.prod\.acquia-sites\.com$';

// Empty by default: personal AI connectors stay disabled until explicitly set.
$settings['coffee_journal_connector_account_uuid'] = getenv('COFFEE_CONNECTOR_ACCOUNT_UUID') ?: '';
$settings['coffee_journal_connector_app_url'] = getenv('COFFEE_CONNECTOR_APP_URL') ?: '';

// Optional OAuth pilot. Keys/secret paths are environment-specific, never config exports.
// Each assistant is registered separately so its callbacks and credential
// cannot authorize another provider's connection.
$settings['coffee_journal_oauth'] = [
  'enabled' => getenv('COFFEE_OAUTH_ENABLED') === '1',
  'issuer' => getenv('COFFEE_OAUTH_ISSUER') ?: '',
  'chatgpt_client_id' => getenv('COFFEE_OAUTH_CHATGPT_CLIENT_ID') ?: '',
  'chatgpt_client_secret' => getenv('COFFEE_OAUTH_CHATGPT_CLIENT_SECRET') ?: '',
  'chatgpt_redirect_uris' => getenv('COFFEE_OAUTH_CHATGPT_REDIRECT_URIS') ?: '',
  'claude_client_id' => getenv('COFFEE_OAUTH_CLAUDE_CLIENT_ID') ?: '',
  'claude_client_secret' => getenv('COFFEE_OAUTH_CLAUDE_CLIENT_SECRET') ?: '',
  'claude_redirect_uris' => getenv('COFFEE_OAUTH_CLAUDE_REDIRECT_URIS') ?: '',
  'private_key' => getenv('COFFEE_OAUTH_PRIVATE_KEY_PATH') ?: '',
  'public_key' => getenv('COFFEE_OAUTH_PUBLIC_KEY_PATH') ?: '',
  'previous_public_key' => getenv('COFFEE_OAUTH_PREVIOUS_PUBLIC_KEY_PATH') ?: '',
  'encryption_key' => getenv('COFFEE_OAUTH_ENCRYPTION_KEY') ?: '',
];

// Optional private runtime file, provisioned server-side outside the web root.
// Environment variables above remain authoritative when configured.
if (getenv('COFFEE_OAUTH_RUNTIME_FILE')) {
  $coffeeOauthRuntimeFile = realpath(getenv('COFFEE_OAUTH_RUNTIME_FILE'));
  if (!$coffeeOauthRuntimeFile || !is_readable($coffeeOauthRuntimeFile)
    || str_starts_with($coffeeOauthRuntimeFile, realpath(DRUPAL_ROOT) . DIRECTORY_SEPARATOR)) {
    throw new \RuntimeException('OAuth runtime file must be readable outside the web root.');
  }
  $coffeeOauthRuntime = json_decode(file_get_contents($coffeeOauthRuntimeFile), TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($coffeeOauthRuntime)) {
    throw new \RuntimeException('Invalid OAuth runtime configuration.');
  }
  foreach (['private_key', 'public_key', 'encryption_key'] as $coffeeOauthKey) {
    if (empty($settings['coffee_journal_oauth'][$coffeeOauthKey]) && isset($coffeeOauthRuntime[$coffeeOauthKey])) {
      $settings['coffee_journal_oauth'][$coffeeOauthKey] = $coffeeOauthRuntime[$coffeeOauthKey];
    }
  }
}
