<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

final class OAuthCommands extends DrushCommands {

  /**
   * Validate private runtime configuration without displaying its secrets.
   */
  #[CLI\Command(name: 'coffee:oauth-check')]
  public function check(): void {
    $settings = \Drupal::service('coffee_journal_oauth.settings');
    if (!$settings->enabled()) {
      $this->output()->writeln('OAuth is disabled. No OAuth journal access is accepted.');
      return;
    }
    \Drupal::service('coffee_journal_oauth.server')->server();
    $settings->keyPath('public');
    if (!\Drupal::service('coffee_journal_api.connections')->pilot()) {
      throw new \RuntimeException('An active journal pilot account must be configured.');
    }
    $this->output()->writeln('OAuth runtime configuration validated for ' . $settings->issuer());
    $this->output()->writeln('MCP resource: ' . $settings->resource());
    $this->output()->writeln('Registration: predefined client. Obtain the exact callback and client settings from ChatGPT before connecting.');
  }
}
