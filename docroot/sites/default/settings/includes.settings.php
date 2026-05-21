<?php

/**
 * @file
 * Central aggregation point for Brewtal / Coffee Journal custom settings files.
 *
 * This file is auto-loaded by acquia-recommended.settings.php.
 * Add any application-specific settings files to the array below.
 */

$additionalSettingsFiles = [
  DRUPAL_ROOT . "/$site_path/settings/coffee_journal.settings.php",
];

foreach ($additionalSettingsFiles as $settingsFile) {
  if (file_exists($settingsFile)) {
    // phpcs:ignore
    require $settingsFile;
  }
}
