<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_auth\Authentication;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;

/**
 * Resolves email identifiers while leaving credential checking to Drupal.
 */
final class EmailAuthentication implements UserAuthenticationInterface, UserAuthInterface {

  public function __construct(
    private readonly UserAuthenticationInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function lookupAccount($identifier): UserInterface|false {
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
      return FALSE;
    }

    // Preserve non-email usernames as a compatibility/recovery path. Never
    // fall back to a username for an email-shaped identifier: a username must
    // not shadow another account's recovery email.
    if (!str_contains($identifier, '@')) {
      return $this->inner->lookupAccount($identifier);
    }

    // Match using Drupal entity storage, just like core password recovery and
    // email uniqueness validation. Do not normalize provider-specific aliases.
    // Include blocked accounts when checking ambiguity: filtering by status
    // could silently select another account with the same email.
    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'mail' => $identifier,
    ]);
    if (count($accounts) !== 1) {
      return FALSE;
    }
    $account = reset($accounts);
    return $account instanceof UserInterface && !$account->isAnonymous() ? $account : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticateAccount(UserInterface $account, #[\SensitiveParameter] string $password): bool {
    return !$account->isAnonymous() && $account->isActive()
      && $this->inner->authenticateAccount($account, $password);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate($username, #[\SensitiveParameter] $password) {
    // Retain compatibility with callers of Drupal's legacy authentication API.
    // Flood control remains the responsibility of the calling login endpoint,
    // exactly as it is for the core authentication service.
    if (!is_string($password) || $password === '') {
      return FALSE;
    }
    $account = $this->lookupAccount($username);
    return $account && $this->authenticateAccount($account, $password) ? $account->id() : FALSE;
  }

}
