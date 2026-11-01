<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class Client implements ClientEntityInterface {
  use ClientTrait, EntityTrait;

  /**
   * @param string[] $redirects
   *   Every exact callback registered for this provider's client.
   */
  public function __construct(string $id, array $redirects, bool $confidential) {
    $this->identifier = $id;
    $this->redirectUri = $redirects;
    $this->name = 'Coffee Journal';
    $this->isConfidential = $confidential;
  }
}
