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
$settings['coffee_journal_oauth'] = [
  'enabled' => getenv('COFFEE_OAUTH_ENABLED') === '1',
  'issuer' => getenv('COFFEE_OAUTH_ISSUER') ?: '',
  'client_id' => getenv('COFFEE_OAUTH_CLIENT_ID') ?: '',
  'client_secret' => getenv('COFFEE_OAUTH_CLIENT_SECRET') ?: '',
  'redirect_uri' => getenv('COFFEE_OAUTH_REDIRECT_URI') ?: '',
  'private_key' => getenv('COFFEE_OAUTH_PRIVATE_KEY_PATH') ?: '',
  'public_key' => getenv('COFFEE_OAUTH_PUBLIC_KEY_PATH') ?: '',
  'previous_public_key' => getenv('COFFEE_OAUTH_PREVIOUS_PUBLIC_KEY_PATH') ?: '',
  'encryption_key' => getenv('COFFEE_OAUTH_ENCRYPTION_KEY') ?: '',
];
