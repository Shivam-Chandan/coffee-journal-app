<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\user\UserInterface;

/**
 * Personal connector credentials. Neither tokens nor account IDs enter config.
 */
final class PersonalConnections {

  private const CHATGPT_APP_URL = 'https://chatgpt.com/plugins/plugins_6ae669f6c27081919016002e9934f120';

  /**
   * Claude has no per-connector deep link, so this opens its connector list.
   */
  private const CLAUDE_CONNECTORS_URL = 'https://claude.ai/customize/connectors';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
  ) {}

  public function pilot(): ?UserInterface {
    $uuid = Settings::get('coffee_journal_connector_account_uuid', '');
    if (!is_string($uuid) || $uuid === '') {
      return NULL;
    }
    $users = $this->entities->getStorage('user')->loadByProperties(['uuid' => $uuid]);
    $user = reset($users);
    return $user instanceof UserInterface && $user->isActive() && !$user->isAnonymous() ? $user : NULL;
  }

  /**
   * Returns the app launcher only for the explicitly enabled personal account.
   */
  public function chatgptAppUrl(AccountInterface $account): ?string {
    if ($account->isAnonymous()) {
      return NULL;
    }
    $pilot = $this->pilot();
    return $pilot && (int) $pilot->id() === (int) $account->id() ? self::CHATGPT_APP_URL : NULL;
  }

  /**
   * Returns Claude's connector page only for the enabled personal account.
   */
  public function claudeConnectorsUrl(AccountInterface $account): ?string {
    if ($account->isAnonymous()) {
      return NULL;
    }
    $pilot = $this->pilot();
    return $pilot && (int) $pilot->id() === (int) $account->id() ? self::CLAUDE_CONNECTORS_URL : NULL;
  }

  public function issue(string $provider = 'chatgpt', int $days = 90, array $scopes = ['beans:read', 'beans:create', 'beans:update']): array {
    if (!in_array($provider, ['chatgpt', 'claude', 'gemini'], TRUE) || $days < 1 || $days > 365
      || !$scopes || array_diff($scopes, ['beans:read', 'beans:create', 'beans:update'])) {
      throw new \InvalidArgumentException('Invalid provider, expiry or scopes.');
    }
    $account = $this->pilot();
    if (!$account) {
      throw new \RuntimeException('Configure an active COFFEE_CONNECTOR_ACCOUNT_UUID before issuing credentials.');
    }
    $token = 'cj_' . bin2hex(random_bytes(32));
    $id = bin2hex(random_bytes(16));
    $this->database->insert('coffee_journal_connection')->fields([
      'id' => $id,
      'token_hash' => hash('sha256', $token),
      'account_uuid' => $account->uuid(),
      'provider' => $provider,
      'scopes' => implode(' ', $scopes),
      'expires' => $this->time->getCurrentTime() + $days * 86400,
      'revoked' => 0,
    ])->execute();
    return ['id' => $id, 'token' => $token];
  }

  public function verify(string $token): ?array {
    if (!preg_match('/^cj_[0-9a-f]{64}$/D', $token)) {
      return NULL;
    }
    $record = $this->database->select('coffee_journal_connection', 'c')->fields('c')
      ->condition('token_hash', hash('sha256', $token))->condition('revoked', 0)
      ->condition('expires', $this->time->getCurrentTime(), '>')->execute()->fetchAssoc();
    $account = $this->pilot();
    if (!$record || !$account || $record['account_uuid'] !== $account->uuid()) {
      return NULL;
    }
    $record['account'] = $account;
    $record['scopes'] = explode(' ', $record['scopes']);
    unset($record['token_hash']);
    return $record;
  }

  public function revoke(string $id): void {
    $this->database->update('coffee_journal_connection')->fields(['revoked' => 1])->condition('id', $id)->execute();
  }

}
