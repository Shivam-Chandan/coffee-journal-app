<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Entity;

use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class User implements UserEntityInterface {
  use EntityTrait;

  public function __construct(string $grantId) {
    $this->identifier = $grantId;
  }
}
