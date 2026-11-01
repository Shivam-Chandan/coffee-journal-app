<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\coffee_journal_api\Service\AssistantConnectionStatusInterface;
use Drupal\Core\Session\AccountInterface;

final class ConnectionStatus implements AssistantConnectionStatusInterface {
  public function __construct(private readonly OAuthSettings $settings, private readonly GrantStore $grants) {}

  public function status(AccountInterface $account): ?array {
    return $this->settings->enabled() ? $this->grants->status($account) : NULL;
  }
}
