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
 */

// ── Google OAuth (Social Auth Google) ──────────────────────────────────────
// Only override when env vars are present (Acquia Cloud environments).
// Locally, credentials are read directly from config/default/social_auth_google.settings.yml.
if (getenv('GOOGLE_CLIENT_ID')) {
  $config['social_auth_google.settings']['client_id'] = getenv('GOOGLE_CLIENT_ID');
}
if (getenv('GOOGLE_CLIENT_SECRET')) {
  $config['social_auth_google.settings']['client_secret'] = getenv('GOOGLE_CLIENT_SECRET');
}

// ── Trusted host patterns ───────────────────────────────────────────────────
$settings['trusted_host_patterns'][] = '^.+\.acquia-sites\.com$';
$settings['trusted_host_patterns'][] = '^eeschandan1(dev|test)?\.prod\.acquia-sites\.com$';
