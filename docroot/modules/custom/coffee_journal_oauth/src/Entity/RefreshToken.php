<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Entity;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class RefreshToken implements RefreshTokenEntityInterface {
  use RefreshTokenTrait, EntityTrait;
}
