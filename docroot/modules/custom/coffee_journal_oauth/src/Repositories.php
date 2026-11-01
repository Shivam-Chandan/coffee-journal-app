<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\coffee_journal_oauth\Entity\{AccessToken, AuthCode, Client, RefreshToken, Scope};
use League\OAuth2\Server\Entities\{AccessTokenEntityInterface, AuthCodeEntityInterface, ClientEntityInterface, RefreshTokenEntityInterface, ScopeEntityInterface};
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\{AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface};

/**
 * Drupal persistence adapters for the maintained OAuth protocol engine.
 */
final class Repositories implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface {

  public function __construct(private readonly OAuthSettings $settings, private readonly GrantStore $store) {}

  public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface {
    $client = $this->settings->client($clientIdentifier);
    return $client ? new Client($clientIdentifier, $client['redirects'], $client['secret'] !== '') : NULL;
  }

  public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool {
    $client = $this->settings->client($clientIdentifier);
    return $client !== NULL && in_array($grantType, ['authorization_code', 'refresh_token'], TRUE)
      && ($client['secret'] === '' || (is_string($clientSecret) && hash_equals($client['secret'], $clientSecret)));
  }

  public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface {
    return in_array($identifier, OAuthSettings::SCOPES, TRUE) ? new Scope($identifier) : NULL;
  }

  public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = NULL, ?string $authCodeId = NULL): array {
    $grant = $userIdentifier ? $this->store->grant($userIdentifier) : NULL;
    $requested = array_map(static fn ($scope) => $scope->getIdentifier(), $scopes);
    if (!$grant || !$requested || array_diff($requested, $grant['scopes'])
      || $clientEntity->getIdentifier() !== $grant['client_id']) {
      throw OAuthServerException::accessDenied('The journal grant or requested scopes are no longer valid.');
    }
    return $scopes;
  }

  public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = NULL): AccessTokenEntityInterface {
    $token = new AccessToken($this->settings->issuer(), $this->settings->resource());
    $token->setClient($clientEntity);
    $token->setUserIdentifier($userIdentifier);
    foreach ($scopes as $scope) {
      $token->addScope($scope);
    }
    return $token;
  }

  public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void {
    $id = $accessTokenEntity->getUserIdentifier();
    $this->store->saveToken('access', $accessTokenEntity->getIdentifier(), $id, $accessTokenEntity->getExpiryDateTime()->getTimestamp());
    $this->store->activate($id);
  }

  public function revokeAccessToken(string $tokenId): void {
    $this->store->revokeToken('access', $tokenId);
  }

  public function isAccessTokenRevoked(string $tokenId): bool {
    return $this->store->revoked('access', $tokenId);
  }

  public function getNewAuthCode(): AuthCodeEntityInterface {
    return new AuthCode();
  }

  public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void {
    $this->store->saveToken('code', $authCodeEntity->getIdentifier(), $authCodeEntity->getUserIdentifier(), $authCodeEntity->getExpiryDateTime()->getTimestamp());
  }

  public function revokeAuthCode(string $codeId): void {
    $this->store->revokeToken('code', $codeId);
  }

  public function isAuthCodeRevoked(string $codeId): bool {
    return $this->store->revoked('code', $codeId);
  }

  public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
    return new RefreshToken();
  }

  public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void {
    $this->store->saveToken('refresh', $refreshTokenEntity->getIdentifier(), $refreshTokenEntity->getAccessToken()->getUserIdentifier(), $refreshTokenEntity->getExpiryDateTime()->getTimestamp());
  }

  public function revokeRefreshToken(string $tokenId): void {
    $this->store->revokeToken('refresh', $tokenId);
  }

  public function isRefreshTokenRevoked(string $tokenId): bool {
    return $this->store->revoked('refresh', $tokenId, TRUE);
  }
}
