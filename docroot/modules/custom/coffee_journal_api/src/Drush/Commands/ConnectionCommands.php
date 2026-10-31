<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Server-administrator-only credential provisioning.
 */
final class ConnectionCommands extends DrushCommands {

  /**
   * Issue a personal coffee connection credential; displays the secret once.
   */
  #[CLI\Command(name: 'coffee:connection-issue')]
  #[CLI\Argument(name: 'provider', description: 'Connection source: chatgpt, claude or gemini.')]
  #[CLI\Argument(name: 'days', description: 'Validity in days, from 1 to 365.')]
  #[CLI\Argument(name: 'scope', description: 'read or read-create.')]
  public function issue(string $provider = 'chatgpt', string $days = '90', string $scope = 'read-create'): void {
    if (!ctype_digit($days) || !in_array($scope, ['read', 'read-create'], TRUE)) {
      throw new \InvalidArgumentException('Use integer days and scope read or read-create.');
    }
    $result = \Drupal::service('coffee_journal_api.connections')->issue($provider, (int) $days,
      $scope === 'read' ? ['beans:read'] : ['beans:read', 'beans:create']);
    $this->output()->writeln('Connection ID: ' . $result['id']);
    $this->output()->writeln('Token (shown once; save in connector authentication): ' . $result['token']);
  }

  /**
   * Revoke a personal coffee connection immediately.
   */
  #[CLI\Command(name: 'coffee:connection-revoke')]
  #[CLI\Argument(name: 'id', description: 'Connection ID returned during issuance.')]
  public function revoke(string $id): void {
    \Drupal::service('coffee_journal_api.connections')->revoke($id);
    $this->output()->writeln('Connection revoked.');
  }

}
