<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class Client implements ClientEntityInterface {
  use ClientTrait, EntityTrait;

  public function __construct(string $id, string $redirect, bool $confidential) {
    $this->identifier = $id;
    $this->redirectUri = $redirect;
    $this->name = 'Coffee Journal in ChatGPT';
    $this->isConfidential = $confidential;
  }
}
