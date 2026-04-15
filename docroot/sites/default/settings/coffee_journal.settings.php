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
//
// social_auth_google ships NO config/install defaults, so the config object
// may not exist in the DB after a fresh module enable.
//
// IMPORTANT — scopes and endpoints field semantics:
//   scopes    = ADDITIONAL scopes beyond the built-in 'email' and 'profile'.
//               Leave empty for basic login — the module adds email+profile itself.
//   endpoints = Google API paths to call POST-login in "path|name" format
//               (e.g. /youtube/v3/playlists?...|playlists). Leave empty for
//               basic login — the userinfo endpoint is handled automatically.
//
if (getenv('GOOGLE_CLIENT_ID')) {
  $config['social_auth_google.settings']['client_id'] = getenv('GOOGLE_CLIENT_ID');
}
if (getenv('GOOGLE_CLIENT_SECRET')) {
  $config['social_auth_google.settings']['client_secret'] = getenv('GOOGLE_CLIENT_SECRET');
}
// Keep scopes and endpoints empty — module provides email+profile by default.
$config['social_auth_google.settings']['scopes']           = '';
$config['social_auth_google.settings']['endpoints']        = '';
$config['social_auth_google.settings']['restricted_domain'] = '';

// ── Bootstrap hook: write config to DB if missing ──────────────────────────
// Runs only when Drupal is fully bootstrapped (i.e. not during CLI install).
// Uses drupal_register_shutdown_function equivalent — hook into DRUPAL_ROOT.
if (defined('DRUPAL_ROOT') && getenv('GOOGLE_CLIENT_ID')) {
  // Queue a one-time config write via a shutdown function.
  // This fires after Drupal bootstraps but before the response is sent.
  // It is safe to call multiple times — configFactory checks existing values.
  register_shutdown_function(function () {
    // Only proceed if Drupal is fully bootstrapped.
    if (!class_exists('\Drupal') || !\Drupal::hasContainer()) {
      return;
    }
    try {
      $existing = \Drupal::config('social_auth_google.settings')->get('client_id');
      // If client_id is empty in DB (despite our override), write it directly.
      if (empty($existing)) {
        \Drupal::configFactory()
          ->getEditable('social_auth_google.settings')
          ->set('client_id',          getenv('GOOGLE_CLIENT_ID') ?: '')
          ->set('client_secret',      getenv('GOOGLE_CLIENT_SECRET') ?: '')
          ->set('scopes',             '')
          ->set('endpoints',          '')
          ->set('restricted_domain',  '')
          ->save();
      }
    }
    catch (\Throwable $e) {
      // Swallow — we must not crash the response for a config write failure.
      error_log('[coffee_journal] social_auth_google config write failed: ' . $e->getMessage());
    }
  });
}

// ── Trusted host patterns ───────────────────────────────────────────────────
$settings['trusted_host_patterns'][] = '^.+\.acquia-sites\.com$';
$settings['trusted_host_patterns'][] = '^eeschandan1(dev|test)?\.prod\.acquia-sites\.com$';
