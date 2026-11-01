<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Entity;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Resource-audienced JWT; the client identity is a separate signed claim.
 */
final class AccessToken implements AccessTokenEntityInterface {
  use AccessTokenTrait, EntityTrait, TokenEntityTrait;

  public function __construct(private readonly string $issuer, private readonly string $resource) {}

  public function toString(): string {
    $config = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($this->privateKey->getKeyContents()), InMemory::plainText('unused'));
    $now = new \DateTimeImmutable();
    return $config->builder()->issuedBy($this->issuer)->permittedFor($this->resource)
      ->identifiedBy($this->getIdentifier())->issuedAt($now)->canOnlyBeUsedAfter($now)
      ->expiresAt($this->getExpiryDateTime())->relatedTo($this->getUserIdentifier())
      ->withClaim('client_id', $this->getClient()->getIdentifier())
      ->withClaim('scopes', array_map(static fn ($scope) => $scope->getIdentifier(), $this->getScopes()))
      ->getToken($config->signer(), $config->signingKey())->toString();
  }
}
