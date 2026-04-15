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
// Strategy (two-layer):
//   1. $config[] override  — works when the config object already exists in DB.
//      Drupal merges these values at bootstrap before any module reads config.
//   2. Bootstrap hook below writes the config directly if the object is absent.
//      This ensures it is present on first enable and after any DB wipe.
//
if (getenv('GOOGLE_CLIENT_ID')) {
  $config['social_auth_google.settings']['client_id'] = getenv('GOOGLE_CLIENT_ID');
}
if (getenv('GOOGLE_CLIENT_SECRET')) {
  $config['social_auth_google.settings']['client_secret'] = getenv('GOOGLE_CLIENT_SECRET');
}
// Always ensure scopes and endpoints are set (social_auth_google requires them).
$config['social_auth_google.settings']['scopes']    = 'email profile';
$config['social_auth_google.settings']['endpoints'] = 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile openid';

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
          ->set('client_id',     getenv('GOOGLE_CLIENT_ID') ?: '')
          ->set('client_secret', getenv('GOOGLE_CLIENT_SECRET') ?: '')
          ->set('scopes',        'email profile')
          ->set('endpoints',     'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile openid')
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
