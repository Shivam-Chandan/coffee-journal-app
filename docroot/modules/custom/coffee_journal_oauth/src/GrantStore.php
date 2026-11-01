<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth;

use Drupal\coffee_journal_api\Service\PersonalConnections;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Stable, revocable grant families and hashed token-identifier records.
 *
 * Grants are scoped to one account and one registered provider client, so each
 * assistant connects, refreshes and disconnects independently.
 */
final class GrantStore {

  public function __construct(
    private readonly Connection $database,
    private readonly PersonalConnections $connections,
    private readonly OAuthSettings $settings,
    private readonly LockBackendInterface $lock,
  ) {}

  public function consent(AccountInterface $account, array $scopes, string $clientId): string {
    $pilot = $this->connections->pilot();
    if (!$pilot || (int) $pilot->id() !== (int) $account->id() || !$scopes
      || array_diff($scopes, OAuthSettings::SCOPES) || !$this->settings->client($clientId)) {
      throw OAuthServerException::accessDenied('This account cannot authorize the journal.');
    }
    $key = 'coffee_oauth_consent:' . hash('sha256', $pilot->uuid() . $clientId);
    if (!$this->lock->acquire($key, 30)) {
      throw OAuthServerException::serverError('Authorization is busy; try again.');
    }
    try {
      $record = $this->database->select('coffee_journal_oauth_grant', 'g')->fields('g')
        ->condition('account_uuid', $pilot->uuid())->condition('client_id', $clientId)
        ->condition('revoked', 0)->condition('expires', time(), '>')->orderBy('created', 'DESC')->range(0, 1)->execute()->fetchAssoc();
      if ($record) {
        $scopes = array_unique(array_merge(explode(' ', $record['scopes']), $scopes));
        $this->database->update('coffee_journal_oauth_grant')->fields(['scopes' => implode(' ', $scopes)])->condition('id', $record['id'])->execute();
        return $record['id'];
      }
      $id = bin2hex(random_bytes(16));
      $this->database->insert('coffee_journal_oauth_grant')->fields([
        'id' => $id, 'account_uuid' => $pilot->uuid(), 'client_id' => $clientId,
        'scopes' => implode(' ', $scopes), 'created' => time(), 'expires' => time() + 30 * 86400,
        'authorized' => 0, 'revoked' => 0,
      ])->execute();
      return $id;
    }
    finally {
      $this->lock->release($key);
    }
  }

  public function grant(string $id, bool $authorized = FALSE): ?array {
    $record = $this->database->select('coffee_journal_oauth_grant', 'g')->fields('g')->condition('id', $id)->execute()->fetchAssoc();
    $pilot = $this->connections->pilot();
    $client = $record ? $this->settings->client($record['client_id']) : NULL;
    if (!$this->settings->enabled() || !$record || !$pilot || !$client || $record['account_uuid'] !== $pilot->uuid()
      || $record['revoked'] || (int) $record['expires'] <= time() || ($authorized && !$record['authorized'])) {
      return NULL;
    }
    return ['id' => $id, 'account' => $pilot, 'scopes' => explode(' ', $record['scopes']),
      'provider' => $client['provider'], 'client_id' => $record['client_id'], 'auth_type' => 'oauth',
      'created' => (int) $record['created'], 'expires' => (int) $record['expires']];
  }

  public function activate(string $id): void {
    $this->database->update('coffee_journal_oauth_grant')->fields(['authorized' => 1, 'created' => time()])
      ->condition('id', $id)->condition('revoked', 0)->condition('authorized', 0)->execute();
  }

  public function revoke(string $id): void {
    $this->database->update('coffee_journal_oauth_grant')->fields(['revoked' => 1])->condition('id', $id)->execute();
  }

  public function record(string $type, string $identifier): ?array {
    $query = $this->database->select('coffee_journal_oauth_token', 't')->fields('t')
      ->condition('id', $type . ':' . hash('sha256', $identifier));
    // Code/refresh reads occur inside the exchange transaction, locking the
    // consumed row even if an advisory lock lease expires on a slow worker.
    if (in_array($type, ['code', 'refresh'], TRUE)) { $query->forUpdate(); }
    $record = $query->execute()->fetchAssoc();
    return $record ? ['grant' => $record['grant_id'], 'expires' => (int) $record['expires'], 'revoked' => (bool) $record['revoked']] : NULL;
  }

  public function saveToken(string $type, string $identifier, string $grantId, int $expires): void {
    $grant = $this->grant($grantId);
    if (!$grant) {
      throw OAuthServerException::accessDenied('The grant is no longer valid.');
    }
    $key = $type . ':' . hash('sha256', $identifier);
    try {
      $this->database->insert('coffee_journal_oauth_token')->fields([
        'id' => $key, 'grant_id' => $grantId, 'expires' => $expires, 'revoked' => 0,
      ])->execute();
    }
    catch (\Drupal\Core\Database\IntegrityConstraintViolationException) {
      throw new \League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException();
    }
  }

  public function revokeToken(string $type, string $identifier): void {
    $this->database->update('coffee_journal_oauth_token')->fields(['revoked' => 1])
      ->condition('id', $type . ':' . hash('sha256', $identifier))->execute();
  }

  public function revoked(string $type, string $identifier, bool $detectReplay = FALSE): bool {
    $record = $this->record($type, $identifier);
    // Refresh-token IDs reach here only after League decrypts and validates client binding.
    if ($detectReplay && $record && $record['revoked']) {
      $this->revoke($record['grant']);
    }
    return !$record || $record['revoked'] || $record['expires'] <= time() || !$this->grant($record['grant']);
  }

  /**
   * Returns each configured provider's authorization status for this account.
   */
  public function status(AccountInterface $account): array {
    $pilot = $this->connections->pilot();
    if (!$pilot || (int) $pilot->id() !== (int) $account->id()) {
      return ['enabled' => FALSE, 'providers' => []];
    }
    $providers = [];
    foreach ($this->settings->clients() as $clientId => $client) {
      $ids = $this->database->select('coffee_journal_oauth_grant', 'g')->fields('g', ['id'])
        ->condition('account_uuid', $pilot->uuid())->condition('client_id', $clientId)
        ->condition('authorized', 1)->orderBy('created', 'DESC')->execute()->fetchCol();
      $entry = ['provider' => $client['provider'], 'status' => $ids ? 'reconnect_required' : 'not_connected'];
      foreach ($ids as $id) {
        if ($grant = $this->grant($id, TRUE)) {
          $entry = ['provider' => $client['provider'], 'status' => 'authorized', 'scopes' => $grant['scopes'],
            'authorized_at' => $grant['created'], 'expires_at' => $grant['expires']];
          break;
        }
      }
      $providers[] = $entry;
    }
    return ['enabled' => TRUE, 'providers' => $providers];
  }

  /**
   * Revokes one provider's grants, leaving other assistants connected.
   */
  public function disconnect(AccountInterface $account, string $provider): void {
    $pilot = $this->connections->pilot();
    $clients = array_keys(array_filter($this->settings->clients(), static fn ($client) => $client['provider'] === $provider));
    if (!$pilot || (int) $pilot->id() !== (int) $account->id() || !$clients) {
      throw OAuthServerException::accessDenied();
    }
    $this->database->update('coffee_journal_oauth_grant')->fields(['revoked' => 1])
      ->condition('account_uuid', $pilot->uuid())->condition('client_id', $clients, 'IN')->execute();
  }
}
