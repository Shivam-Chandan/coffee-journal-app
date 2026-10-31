<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Mcp;

use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Expiring server sessions, isolated by authenticated connection collection.
 */
final class ConnectionSessionStore implements SessionStoreInterface {

  public function __construct(private readonly KeyValueStoreExpirableInterface $store) {}

  public function exists(Uuid $id): bool {
    return $this->read($id) !== FALSE;
  }

  public function read(Uuid $id): string|false {
    $value = $this->store->get((string) $id);
    return is_string($value) ? $value : FALSE;
  }

  public function write(Uuid $id, string $data): bool {
    $this->store->setWithExpire((string) $id, $data, 3600);
    return TRUE;
  }

  public function destroy(Uuid $id): bool {
    $this->store->delete((string) $id);
    return TRUE;
  }

  public function gc(): array {
    // Drupal's expirable storage expires reads and garbage-collects on cron.
    return [];
  }

}
