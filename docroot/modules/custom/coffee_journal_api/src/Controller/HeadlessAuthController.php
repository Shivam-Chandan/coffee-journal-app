<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Session login and logout for the headless frontend.
 *
 * Credential checking, password hashing, session handling and recovery mail all
 * stay in Drupal. This controller only adapts them to a JSON contract and keeps
 * core's IP and per-account flood budgets intact.
 */
final class HeadlessAuthController extends ControllerBase {

  public function __construct(
    private readonly HeadlessPresenter $presenter,
    private readonly UserAuthenticationInterface $userAuth,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.presenter'),
      $container->get('user.auth'),
      $container->get('flood'),
    );
  }

  /**
   * Authenticates an existing account and rotates the session.
   */
  public function login(Request $request): JsonResponse {
    if ($this->currentUser()->isAuthenticated()) {
      return $this->presenter->error('already_authenticated', 'You are already signed in.', 409);
    }
    $payload = $this->decode($request);
    $identifier = is_string($payload['identifier'] ?? NULL) ? trim($payload['identifier']) : '';
    $password = is_string($payload['password'] ?? NULL) ? $payload['password'] : '';
    if ($identifier === '' || $password === '') {
      return $this->presenter->error('validation_failed', 'Enter your email and password.', 422, [
        'identifier' => $identifier === '' ? 'Enter your email or username.' : '',
        'password' => $password === '' ? 'Enter your password.' : '',
      ]);
    }

    $config = $this->config('user.flood');
    $ip = (string) $request->getClientIp();
    if (!$this->flood->isAllowed('user.failed_login_ip', $config->get('ip_limit'), $config->get('ip_window'), $ip)) {
      return $this->tooManyAttempts($config->get('ip_window'));
    }

    $account = $this->resolveAccount($identifier);
    // Consume the per-account budget using the same identity core uses, so
    // switching between email and username cannot reset the budget.
    $accountIdentifier = NULL;
    if ($account instanceof UserInterface) {
      $accountIdentifier = $config->get('uid_only')
        ? $account->id()
        : $account->id() . '-' . $ip;
      if (!$this->flood->isAllowed('user.failed_login_user', $config->get('user_limit'), $config->get('user_window'), $accountIdentifier)) {
        return $this->tooManyAttempts($config->get('user_window'));
      }
    }

    if (!$account instanceof UserInterface || !$this->userAuth->authenticateAccount($account, $password)) {
      $this->flood->register('user.failed_login_ip', $config->get('ip_window'), $ip);
      if ($accountIdentifier !== NULL) {
        $this->flood->register('user.failed_login_user', $config->get('user_window'), $accountIdentifier);
      }
      // Never reveal whether the account exists or is blocked.
      return $this->presenter->error('invalid_credentials', 'Those details did not match an account.', 401);
    }

    $this->flood->clear('user.failed_login_user', $accountIdentifier);
    // Core finalizes login: it regenerates the session and records the login.
    user_login_finalize($account);

    return $this->presenter->response([
      'data' => [
        'authenticated' => TRUE,
        'user' => [
          'id' => (int) $account->id(),
          'name' => $account->getDisplayName(),
          'email' => (string) $account->getEmail(),
          'can_create' => $this->entityTypeManager()
            ->getAccessControlHandler('node')
            ->createAccess('coffee_bean', $account),
        ],
      ],
    ]);
  }

  /**
   * Ends the current session.
   */
  public function logout(): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return $this->presenter->response(['data' => ['authenticated' => FALSE]]);
    }
    user_logout();
    return $this->presenter->response(['data' => ['authenticated' => FALSE]]);
  }

  /**
   * Requests a password reset email using Drupal's supported mechanism.
   */
  public function requestPasswordReset(Request $request): JsonResponse {
    $payload = $this->decode($request);
    $identifier = is_string($payload['identifier'] ?? NULL) ? trim($payload['identifier']) : '';
    if ($identifier === '') {
      return $this->presenter->error('validation_failed', 'Enter your email or username.', 422, [
        'identifier' => 'Enter your email or username.',
      ]);
    }
    $config = $this->config('user.flood');
    $ip = (string) $request->getClientIp();
    if (!$this->flood->isAllowed('user.password_reset_ip', $config->get('ip_limit'), $config->get('ip_window'), $ip)) {
      return $this->tooManyAttempts($config->get('ip_window'));
    }
    $this->flood->register('user.password_reset_ip', $config->get('ip_window'), $ip);

    $account = $this->resolveAccount($identifier);
    if ($account instanceof UserInterface && $account->isActive()) {
      $this->flood->register('user.password_reset', 21600, $account->id());
      if ($this->flood->isAllowed('user.password_reset', 5, 21600, $account->id())) {
        _user_mail_notify('password_reset', $account);
      }
    }
    // Always answer the same way so account existence stays private.
    return $this->presenter->response([
      'data' => [
        'message' => 'If that account exists, we have sent password reset instructions.',
      ],
    ]);
  }

  /**
   * Resolves an email or username identifier to exactly one account.
   *
   * Email-shaped identifiers resolve only through the mail field and fail
   * closed on duplicates, so a username can never shadow another account's
   * registered email. This mirrors the rules in the email-login plan and stays
   * correct whether or not the optional email-login module is enabled.
   */
  private function resolveAccount(string $identifier): UserInterface|false {
    if (!str_contains($identifier, '@')) {
      $account = $this->userAuth->lookupAccount($identifier);
      return $account instanceof UserInterface ? $account : FALSE;
    }
    // Include blocked accounts when checking ambiguity: filtering by status
    // could silently select a different account with the same address.
    $accounts = $this->entityTypeManager()->getStorage('user')
      ->loadByProperties(['mail' => $identifier]);
    if (count($accounts) !== 1) {
      return FALSE;
    }
    $account = reset($accounts);
    return $account instanceof UserInterface && !$account->isAnonymous() ? $account : FALSE;
  }

  /**
   * Returns a neutral throttling response with a retry hint.
   */
  private function tooManyAttempts(int $window): JsonResponse {
    $response = $this->presenter->error(
      'too_many_attempts',
      'Too many attempts. Please wait before trying again.',
      429
    );
    $response->headers->set('Retry-After', (string) $window);
    return $response;
  }

  /**
   * Decodes a small JSON object body, returning an empty array when invalid.
   */
  private function decode(Request $request): array {
    $body = (string) $request->getContent();
    if ($body === '' || strlen($body) > 4096) {
      return [];
    }
    try {
      $payload = json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    return is_array($payload) && !array_is_list($payload) ? $payload : [];
  }

}
