<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Optional OAuth grant-status integration for the browser profile contract.
 */
interface ChatgptConnectionStatusInterface {
  public function status(AccountInterface $account): ?array;
}
