<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Entity;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

final class AuthCode implements AuthCodeEntityInterface {
  use AuthCodeTrait, EntityTrait, TokenEntityTrait;
}
