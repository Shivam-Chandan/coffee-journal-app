<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\coffee_journal_api\Service\AssistantConnectionStatusInterface;
use Drupal\Core\Session\AccountInterface;

final class ConnectionStatus implements AssistantConnectionStatusInterface {
  public function __construct(private readonly OAuthSettings $settings, private readonly GrantStore $grants) {}

  public function status(AccountInterface $account): ?array {
    if (!$this->settings->enabled()) {
      return NULL;
    }
    $status = $this->grants->status($account);
    // Setup needs the exact MCP URL the connector must point at.
    if ($status['enabled']) {
      $status['mcp_url'] = $this->settings->resource();
    }
    return $status;
  }
}
