# Coffee Journal native authentication — first increment

This module adds email lookup to the **rendered** Drupal login form. It is not
the completed migration or an invitation sender. Install/activation is a separate
operational step after the gates below have been met.

## Implementation decisions

- Drupal remains the account store. Lookup does not create, merge, rename, or
  modify accounts. Core checks passwords and handles password rehashing, login
  sessions, and IP/UID flood control.
- The existing core login form is subclassed only to inject the email-aware
  authentication service. Its validation and submission handlers are inherited.
  The small dependency on core's internal form class is covered by kernel tests
  and must be checked on core upgrades.
- Email-shaped identifiers (containing `@`) resolve only through the `mail`
  field. Multiple matches fail closed, even if one account is blocked. There is
  no fallback to an email-shaped username. Such usernames need a valid unique
  registered email for browser login.
- Non-email usernames remain available for existing users and administrator
  recovery. They are not restricted to administrators. Names and display names
  are preserved.
- Matching uses `loadByProperties(['mail' => $identifier])`, as core recovery
  does. Only surrounding whitespace is trimmed. Database collation determines
  case sensitivity; SQLite rehearsal is case-sensitive. Verify matching and
  uniqueness on the target database, including case variants, before activation.
  Gmail dots/plus aliases are not transformed.
- A narrow custom integration avoids changing username generation, account
  fields, or registration semantics, and requires no Composer change. This is
  not a claim of independent security coverage. A maintained contrib alternative
  remains a review option; its current release/security status has not been
  verified (the Drupal project page returned a client challenge).
- This is deliberately **not** a global `user.auth` decorator. Core JSON login
  currently computes its per-user flood identifier using a username lookup.
  Adding email authentication globally would require updating and testing that
  controller's limits too. JSON/HTTP authentication contracts are unchanged.
- Google routes, callbacks, associations, dependencies, and profile imports are
  preserved. The theme switches to the native-primary layout only while this
  module is enabled. Disabling it and rebuilding caches restores Google-primary
  presentation without changing established passwords.

## Activation gates and rehearsal

1. Obtain the exported-configuration scope exception. Coordinate ownership of
   `user.settings` with the security implementation agent. Enable and export
   `verify_mail: true` through Drupal, then verify controlled registration,
   email uniqueness, Google creation/linking, and existing-account login. Do not
   activate this increment with the repository's unverified registration policy.
2. Use a protected dev/test environment with captured/redirected email. Inventory
   duplicate/missing/case-variant addresses before inviting any cohort. Core
   recovery currently selects the first email match, so duplicate accounts must
   remain exceptions rather than being sent automated setup links.
3. Verify the effective installed versions/configuration, outbound mail, trusted
   hostname, administrator recovery, and Google account-linking behavior.
4. After activation approval, enable `coffee_journal_auth`, rebuild caches, and
   export configuration through the normal workflow. Enabling the module must
   be represented in `core.extension` before deployment config import can retain
   it. No exported configuration is included in this increment.
5. Rehearse browser login with existing native and Google-associated accounts,
   long email identifiers, blocked users, both UID/IP flood limits, Google login,
   logout/session rotation, and unchanged ownership/profile/interaction data.
   Verify styling and recovery/registration links in the actual theme. Local
   functional tests now cover the native flows, actual login-template rendering,
   and presentation rollback, but not real Google identities or production data.

## Recovery gaps to close before invitations

The links in the form point to core `user.pass`; no custom tokens are issued.
Source review of Drupal 11.3.8 shows that:

- Core password recovery has IP and UID flood controls and a neutral response.
- Reset-link hashes depend on password and last-login state. Issuing another
  link alone does not invalidate previous links; do not promise latest-link-only
  behavior.
- Core does **not** enforce its normal reset timeout for users with no prior
  login. A bounded setup flow needs an additional supported-mechanism expiry
  guard for those accounts before this plan's time-limited-link requirement is
  met.
- One-time login opens account editing. Local functional tests confirm that
  saving the reset-authorized form requires a password, but opening the link
  alone does not set a password. No migration completion state is recorded here.

Verified email changes, forced successful password setup, setup-page resource/
referrer controls, invitation state/queue/CLI, registration/resend throttles,
Google linking verification, full reset/session regression tests, and rollout
approvals are outstanding. Keep invitation sending disabled until those gates
are met. Do not rotate hash salt during invitation batches.

## Focused tests

From the repository root, with the project's PHP dependencies installed:

```sh
SIMPLETEST_DB=sqlite://localhost/:memory: php vendor/bin/phpunit \
  --no-configuration --bootstrap docroot/core/tests/bootstrap.php \
  docroot/modules/custom/coffee_journal_auth/tests/src/Kernel/EmailLoginTest.php
```

These tests use isolated Drupal kernel fixtures, real password checks/entity
storage, and the inherited form validation/flood handling. They send no email.

For HTTP/browser functional tests on an existing DDEV instance:

```sh
ddev exec mkdir -p /tmp/coffee-journal-auth-browser
ddev exec env SIMPLETEST_BASE_URL=http://web \
  SIMPLETEST_DB=mysql://db:db@db/db \
  BROWSERTEST_OUTPUT_DIRECTORY=/tmp/coffee-journal-auth-browser \
  php vendor/bin/phpunit --no-configuration \
  --bootstrap docroot/core/tests/bootstrap.php \
  docroot/modules/custom/coffee_journal_auth/tests/src/Functional/NativeAuthenticationTest.php
```

Functional tests install separate, temporary, table-prefixed sites. They enable
verified registration and the auth/Google modules only in those sites, capture
mail through Drupal's test collector, and exercise real HTTP sessions without
changing the shared DDEV site's configuration or sending real email. They test
the Coffee Journal login template, presentation rollback, native login/logout,
blocked accounts, recovery/password-save/replay behavior, duplicate signup,
verified signup through password setup and native login, and neutral unknown
recovery. Google OAuth itself, Google account linking, JavaScript execution,
production data, and full migration/invitation behavior remain separate checks.

Local evidence (2026-10-30): PHP 8.3.32; kernel **8 tests / 56 assertions**;
MySQL 8 functional **4 tests / 76 assertions**; Drupal/DrupalPractice PHPCS
passed. The functional suite caught and fixed nested document markup in the
login page template. A local cache rebuild succeeded; Chrome confirmed a single
document, no unresolved asset placeholders, and the Google/native-form fallback
on the shared site's Google-primary presentation.
