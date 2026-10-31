# Email/password authentication migration — agent execution plan

Date: 2026-10-30
Status: implementation started; native browser-login increment implemented and kernel-tested, activation pending.

## Goal and agreed decisions

Replace Google sign-in with email/password authentication using existing Drupal accounts. Preserve all user data and prepare for a future JavaScript, web-only frontend.

- Drupal remains the account store and authorization authority.
- Preserve existing user IDs (UIDs), UUIDs, roles, account status, profile fields, and ownership references.
- Existing users choose their own password through a time-limited, one-time password-setup flow based on Drupal's supported account/reset mechanisms.
- Do not assign or email temporary passwords. Setting a random password alone does not make it one-time.
- New registrations require email verification.
- Support email login explicitly; Drupal's default username form must not merely be relabeled.
- Retain Google during a measured transition, then retire it.
- Future headless target: web-only JavaScript frontend, preferably same public origin as proxied Drupal authentication/API requests, with secure HttpOnly session cookies and CSRF protection.
- Do not introduce an external identity provider, custom JWT system, mobile flow, or separate JavaScript account database for this migration.

## Authority, scope, and working rules

This document is the requested implementation handoff. It does not itself authorize sending real-user email, production database mutations, deployments, credential revocation, or destructive module/data cleanup. Obtain those operational approvals at the relevant stage.

Read `CLAUDE.md` and applicable `AGENTS.md`, inspect Git status, and preserve unrelated work. Project rules currently limit changes to custom modules/themes; obtain an explicit exception for Composer, exported configuration, runtime settings, or workflows when needed. Do not edit core, contrib, or vendor code.

Keep business logic and Form API integration in custom modules; markup and presentation in the theme. Use Drupal services/entity APIs and supported reset mechanisms. Never implement password hashing or reset-token cryptography yourself. Export configuration through the normal Drupal workflow. Clear caches after routing/module/theme/template/configuration changes.

Coordinate with `docs/critical-security-implementation-plan.md`, particularly CSRF, private-content access, credential handling, and rate limiting. Avoid concurrent hash-salt rotation during invitation batches: it can invalidate reset links. Finalize secret changes first, or plan explicit link reissuance.

## Source-grounded starting points

- `composer.json` requires `drupal/social_auth_google: ^4.0` and includes a `social_api` patch.
- `config/default/social_auth.settings.yml` sets the post-login destination to `/feed`.
- `config/default/user.settings.yml` currently permits visitor registration with email verification disabled.
- `docroot/themes/custom/coffee_journal/templates/user/page--user--login.html.twig` prioritizes Google and renders native form content under an "Admin access" disclosure. Its comments about admin-only access are not proof of backend restrictions; verify actual access behavior.
- `docroot/modules/custom/coffee_journal_api/coffee_journal_api.info.yml` depends on `social_auth`.
- `coffee_journal_api.module` imports Google profile information on login and saves pictures under `public://social_auth_google_profiles/`.
- `coffee_journal_api/src/Controller/UserStateController.php` serves `/api/user/me` using the current Drupal account.
- Like/bookmark repositories and profile/content queries reference Drupal UID.

Live account counts, email conflicts, provider mappings, password usability, delivery configuration, and deployment behavior have not been inspected. Verify these rather than assuming source configuration matches production.

## Phase 0 — Preflight and implementation design

- [ ] Record installed Drupal/contrib versions and existing authentication, registration, email, and reset behavior.
- [ ] Trace all Google dependencies: module metadata, routes, templates, hooks, configuration, views, Composer dependencies/patches, and runtime settings.
- [ ] Select a maintained Drupal-11-compatible email-login integration. Check compatibility, security coverage, email matching rules, throttling, and reset flows. If a small custom integration is needed, use Drupal authentication services and document why.
- [x] Specify login identifier policy. Email is the user-facing identifier; define whether existing username login remains as an administrative compatibility path. Preserve usernames/display names unless independently required.
- [x] Define email uniqueness/matching consistently with Drupal and the selected integration. Do not apply provider-specific transformations such as removing Gmail dots or plus suffixes.
- [ ] Verify the existing Google account-linking policy during overlap; prevent unverified new accounts from capturing an existing identity through email matching.
- [ ] Agree an operational transition window (initial proposal: 2–4 weeks), pilot size, batch size, throttle settings, activation criteria, rollback window, and support owner.

Deliverable: brief implementation decisions recorded in the execution log, including required scope exceptions.

## Phase 1 — Inventory, backup, and rehearsal

- [ ] With authorization, back up database and managed files; verify a restore procedure.
- [ ] Prepare a protected non-production rehearsal environment. Disable/redirect outbound email and scheduled invitation jobs before loading real user data. Prefer anonymized fixtures for automated tests.
- [ ] Build a read-only inventory using Drupal entities and installed provider-association storage: UID, UUID, status, roles, email issues, provider association, recent activity, profile/file completeness, and per-user content/interaction counts.
- [ ] Identify Google-associated users separately from local-only users, blocked users, service accounts, and administrators. Exclude anonymous UID 0 and handle privileged accounts separately.
- [ ] Resolve missing/conflicting emails through an auditable exception process. Do not merge accounts or transfer ownership solely because emails match.
- [ ] Record baseline invariants: UIDs/UUIDs, roles/status, owner references, likes/bookmarks, profile field values, and managed-file references.
- [ ] Verify email delivery from the intended sender, trusted site/reset-link hostname, queue behavior, and bounce handling using controlled recipients.

Keep inventory restricted and outside public files/version control. Never export password hashes, OAuth tokens, reset links, or credentials into reports/logs. A stored password hash does not establish that a user knows a usable local password.

Acceptance: all eligible accounts are classified; exceptions are actionable; backup/rehearsal and mail delivery work; no real-user invitations have been sent.

## Phase 2 — Native authentication and account recovery

- [ ] Make email/password the primary login UI; retain Google as a secondary transition option.
- [ ] Add visible registration, "Forgot password / Set up password", password change, and logout flows.
- [ ] Enable email verification for new registration and enforce uniqueness through the chosen supported workflow. Prevent unverified accounts from gaining full application access.
- [ ] Use Drupal's reset/one-time-login mechanisms to open a password-setting flow for an existing UID. Verify the installed version's behavior: expiration, successful-use invalidation, intervening login effects, and whether multiple issued links coexist. Do not claim latest-link-only semantics unless enforced.
- [ ] Require successful password validation and save before marking setup complete. Link issuance/opening alone is not activation. Do not assume a one-time-login URL forces password creation without testing the form flow.
- [ ] Ensure the migration flow never creates accounts and does not replace already-working local passwords until the user intentionally saves a new one.
- [ ] Require verified email changes; do not let an unverified email edit silently redirect account recovery. Preserve blocked-account restrictions.
- [ ] Provide neutral recovery responses, bounded requests, safe redirect destinations, and useful expired/invalid-link recovery without exposing account existence.
- [ ] Apply configurable login, registration, invitation-resend, and recovery throttles. Preserve native Drupal brute-force protections.
- [ ] Verify CSRF/session protections, session rotation on authentication, logout, password-change session behavior, and private response caching. Keep reset URLs out of logs/analytics and prevent third-party resource leakage on setup pages.
- [ ] Keep a tested administrator recovery path.

Acceptance: controlled users can register, verify, sign in by email, recover, change password, and log out. Existing Google users set a password on the same account; blocked users cannot activate. Google login still works during overlap.

## Phase 3 — Resumable invitation and migration tooling

- [ ] Implement a dry-run-first administrative CLI/queue workflow for explicit eligible cohorts. Reuse Drupal mail/reset services rather than custom credential generation.
- [ ] Store minimal migration state per UID: eligibility, invitation attempts/timestamps, delivery outcome when available, setup completion, first successful native login, and exception reason. Do not store plaintext setup tokens or links.
- [ ] Make processing resumable and idempotent: avoid duplicate queue items and uncontrolled resends; distinguish delivery retries from explicitly requested reinvitations.
- [ ] Recheck account eligibility/status/email at send time. Generate valid links near actual send time rather than queuing expiring URLs far in advance. Fail safely on mail transport errors.
- [ ] Add a signed-in prompt for Google users to set up email login through the verified flow.
- [ ] Provide aggregate progress reporting without exposing personal data publicly. Count setup completion and native login separately.
- [ ] Document exceptions for lost mailbox access, duplicates, bounced mail, and privileged accounts. Use an approved identity-recovery process; never bypass proof of ownership merely on a support request.

Suggested invitation copy: explain that the same account and coffee data remain, ask the user to choose an app password, and state that their Google password is neither required nor transferred.

Acceptance: rehearsal batches can stop/restart without unintended duplicate invitations; setup targets the original UID; no secrets leak in logs; failed mail remains retryable; returning users can request a fresh link without creating a new account.

## Phase 4 — Pilot and production transition

- [ ] Obtain approval for deployment and the specific invitation cohort before sending real email.
- [ ] Deploy to dev/test first and verify all acceptance tests below. Run applicable configuration/database updates and cache rebuilds.
- [ ] Pilot with controlled accounts and separately verified administrators, then a small consenting active-user cohort.
- [ ] Compare baseline ownership/profile invariants and verify private-data access after native login.
- [ ] Expand in bounded batches while monitoring delivery/bounces, setup success, native-login success, recovery failures, throttles, and duplicate incidents.
- [ ] Publish the Google cutoff date and send bounded reminders according to the agreed policy.
- [ ] Pause invitations on material failures; keep Google available while correcting issues.

Google cutoff gate: pilot/data checks passed, acceptable delivery and active-user adoption, functioning recovery for users who missed invitations, resolved critical exceptions, support owner ready, and explicit cutoff approval. Do not require every inactive user to activate or disable their accounts because they did not migrate.

## Phase 5 — Disable Google, then remove dependencies

- [ ] Disable the actual Google authentication entry/callback flow, not just the visible button; verify behavior for in-flight callbacks.
- [ ] Keep provider associations and required packages/configuration during the agreed rollback window.
- [ ] Verify native login/recovery, new registration, profiles, content permissions, and APIs with Google disabled.
- [ ] Remove the custom API module's `social_auth` dependency and Google profile-import hooks when safe. Preserve imported user fields and all referenced image files, including files in Google-named directories.
- [ ] Inventory dependent configuration such as `views.view.social_auth_profiles.yml`; inspect module uninstall behavior on the rehearsal environment before any destructive action.
- [ ] After approval and rollback-window closure, uninstall unused Google/social-auth modules in correct dependency order. Ensure uninstalling them cannot uninstall the custom API module or delete required user/profile data.
- [ ] Remove unused Composer packages, obsolete patches, runtime overrides, and callback configuration only after dependency checks. Rehearse the deployment sequence with package code present while uninstall runs.
- [ ] Retire Google OAuth client credentials after successful cleanup. Do not remove Gemini or Places credentials; these are unrelated services.
- [ ] Dispose of temporary inventory/mapping artifacts according to the agreed retention policy and record completion.

Acceptance: Google no longer authenticates users; all native flows work; custom API remains enabled; user/content/profile/interaction invariants are unchanged; dormant users can still recover via registered email.

## Required regression checks

- [ ] Existing Google user completes setup and native login with identical UID/UUID and all existing data.
- [ ] Existing local-password user retains working credentials until intentionally changing them.
- [ ] Valid, expired, tampered, already-used, and repeated-issued setup links behave as documented; successful use cannot be replayed.
- [ ] Link opened without completing password setup does not falsely mark migration complete; recovery still works.
- [ ] Existing-email signup cannot create an unintended duplicate or take over an account, including during Google overlap.
- [ ] Blocked users remain blocked; roles and permissions do not change.
- [ ] Email-change, lost-email, bounce, retry, and interrupted-batch cases follow documented policy.
- [ ] Two-user fixtures prove private content and interaction data stay inaccessible across accounts.
- [ ] Login/recovery/registration limits and CSRF protections work without exposing emails or tokens.
- [ ] Authenticated pages and `/api/user/me` cannot leak through shared caches.
- [ ] Logout, session expiry, password reset/change, and administrator recovery work.
- [ ] Disable/uninstall rehearsal leaves required modules, profile pictures, references, and data intact.

Use appropriate unit/kernel/functional/browser tests for changed behavior and controlled mail capture. Report commands, results, and blockers; do not claim live validation from source-only review.

## Rollback

Before destructive cleanup, re-enable the tested Google flow and restore the prior login presentation if needed. Preserve newly established passwords and all writes made during the transition. Do not restore an old database as a routine rollback: it would discard new user activity.

Keep cleanup in a separate release from enabling native login. After package/module/association removal, rollback requires a separately rehearsed restoration process; this is why destructive cleanup and OAuth revocation are delayed. Keep recovery available throughout.

## Future headless handoff (not implementation scope)

Replace rendered screens later, retaining Drupal accounts, UIDs, and access checks. Prefer one public origin routing UI to the JavaScript application and auth/API to Drupal. Define login/session/logout/reset contracts, CSRF behavior, secure HttpOnly cookies, and per-user cache boundaries before decoupling. SSR must forward sessions per request without sharing authenticated cache entries.

No external-provider migration, JWT issuance, or browser local-storage tokens are needed now. Reassess OAuth/OIDC only if future requirements introduce independently hosted clients, mobile apps, or third-party access. Update the project's traditional-Drupal architectural rules explicitly before headless implementation.

## Next agent starting checklist

1. Read this plan and project instructions; inspect current Git status.
2. Complete Phase 0 and record integration/version choices and scope permissions.
3. Implement and test native flows against controlled fixtures before accessing production data or sending invitations.
4. Maintain this checklist as work proceeds; keep operationally blocked tasks unchecked.
5. Handoff with changed files, test evidence, migration counts (aggregate only), exceptions, deployment state, next approvals, and rollback readiness.

## Execution log

- 2026-10-30: Plan created from user decisions: email/password, web-only, existing Drupal accounts, one-time password-setup links. No authentication code, account records, mailings, or environments changed.
- 2026-10-30: Implementation increment 1 added in `docroot/modules/custom/coffee_journal_auth/`, isolated from the security agent's API/routes/JS/workflow edits. Theme changes are limited to preprocessing, the login template, a new library, and dedicated CSS. Existing security-agent changes were preserved.
- Source inventory: checked-out core and Composer lock report Drupal **11.3.8**, social_auth **4.1.2**, social_auth_google **4.0.3**, social_api **4.0.2**; these are not verified live versions. Native registration export still has `verify_mail: false`; reset timeout is 86400 seconds; Google post-login destination is `/feed`. Google dependencies remain in Composer/patches, module metadata, profile-import hook, settings overrides, provider config, and the social-auth profiles view. Live mail, provider associations, counts, and linking policy remain unverified.
- Identifier decision: trim surrounding whitespace; email-shaped identifiers resolve through Drupal's `mail` property only; ambiguous matches fail closed including active/blocked conflicts. No provider alias normalization. Database collation governs case matching, consistently with core recovery; target-database case-variant uniqueness/matching still needs rehearsal. Existing **non-email** usernames remain a compatibility path for all users, including administrators. No account rename or data migration is performed.
- Integration decision: a small custom authentication adapter is injected only into the rendered core login form. Core password verification, inherited UID/IP flood controls, and session-finalization code are retained. Global `user.auth` is unchanged: source review found username-only UID lookup in core JSON-login flood accounting, so global email authentication would require separate hardening/testing. The core form subclass is an upgrade-sensitive integration point. Maintained contrib release/security coverage was not confirmed (Drupal project page fetch returned a client challenge); that selection review remains open. See the new module README for detailed decisions and activation gates.
- Theme switches to email-primary presentation only when `coffee_journal_auth` is enabled, with recovery/setup and registration links and Google retained as the secondary option. Until activation, the existing Google-primary layout remains. No module-enable/exported config, Composer changes, invitation state, or email-change workflow is included in this increment.
- Validation: `SIMPLETEST_DB=sqlite://localhost/:memory: /opt/homebrew/bin/php vendor/bin/phpunit --no-configuration --bootstrap docroot/core/tests/bootstrap.php docroot/modules/custom/coffee_journal_auth/tests/src/Kernel/EmailLoginTest.php` passed **8 tests / 56 assertions**, using PHP **8.5.11** and isolated SQLite fixtures. Covers same-account identity/field/password preservation, username compatibility, missing/ambiguous emails, username/email collisions, blocked accounts, shared UID flood limits, independent IP limits, long emails, recovery links, and route isolation. Drupal/DrupalPractice PHPCS passed for the new module. PHP 8.3, target-database collation, browser/session/OAuth/mail, and ownership/interaction regressions remain rollout gates.
- Cache rebuild attempted using `PATH="/opt/homebrew/bin:/usr/local/bin:$PATH" vendor/bin/drush --root=docroot cache:rebuild --yes`; blocked by missing local `$settings['hash_salt']`. The kernel tests rebuilt their isolated test router; no application-cache rebuild or deployment is claimed.
- Reset-flow finding for Phase 2: core 11.3.8 skips normal reset expiry for never-logged-in accounts and does not force successful password saving merely by opening a one-time link. Add/test bounded setup expiry and successful-save tracking before invitations. Core recovery's first-match behavior also means duplicate-email exceptions must be resolved/excluded before setup mail. No invitations were sent.
- Next approvals/dependencies: exported-config scope exception; coordinated ownership of `user.settings` with the security agent; protected rehearsal environment and mail capture; Google linking review; native module activation/deployment approval; transition/pilot/support policy. Do not enable the new module until email-verified registration and the documented rehearsal gates pass. Invitation/cutoff/cleanup phases remain unchecked and operationally gated.
- 2026-10-30 local testing follow-up (user authorized): used the other agent's running DDEV instance, PHP **8.3.32** and MySQL **8.0**. Tests installed isolated, temporary table-prefixed sites, with native auth/Google modules and verified registration enabled only inside those sites; mail was captured by Drupal's test collector. Shared DDEV module/theme/registration configuration was not changed by this work. That shared configuration was actively evolving under the other agent, so initial/final fixture counts are not treated as migration evidence.
- DDEV kernel command: `ddev exec env SIMPLETEST_DB=sqlite://localhost/:memory: php vendor/bin/phpunit --no-configuration --bootstrap docroot/core/tests/bootstrap.php docroot/modules/custom/coffee_journal_auth/tests/src/Kernel/EmailLoginTest.php` — **8 tests / 56 assertions passed** on PHP 8.3.32.
- Added `tests/src/Functional/NativeAuthenticationTest.php` in the new auth module. Command: `ddev exec env SIMPLETEST_BASE_URL=http://web SIMPLETEST_DB=mysql://db:db@db/db BROWSERTEST_OUTPUT_DIRECTORY=/tmp/coffee-journal-auth-browser php vendor/bin/phpunit --no-configuration --bootstrap docroot/core/tests/bootstrap.php docroot/modules/custom/coffee_journal_auth/tests/src/Functional/NativeAuthenticationTest.php` — **4 tests / 76 assertions passed**. Covers actual Coffee Journal login-template rendering, email-primary and Google-primary rollback layouts, native email login/logout, blocked users, captured reset mail, unchanged password before setup, required password on reset-authorized save, successful password change on original UID/UUID, replay rejection, old-password rejection/new-password login, duplicate-email registration rejection, verified new registration through email-link/password setup/native login, and neutral unknown-account recovery. These are real HTTP/Mink functional tests, not JavaScript-driven browser tests or live Google OAuth validation.
- Local tests found and fixed the login template's pre-existing nested `doctype/html/head/body` wrapper and unresolved asset placeholders. Drupal's outer HTML template now owns the document; login body class is added in theme preprocessing. `ddev drush cache:rebuild` succeeded. A separate Chrome session at `https://brewtal.ddev.site/user/login` confirmed one HTML document, zero unresolved asset placeholders, the Google link, and the native-form fallback in the shared site's Google-primary layout. No shared-site auth-module activation was performed.
- DDEV Drupal/DrupalPractice PHPCS passed for the auth module. Combined local automated result: **12 tests / 132 assertions**. Remaining gates include controlled Google identity/linking validation, JavaScript/mobile visual checks of the email-primary layout, production-data ownership/profile/interaction invariants, reset expiry for never-logged-in accounts, verified email changes, migration state/invitations, config export, and deployment approval. No real-user mail or deployment occurred.
- 2026-10-31 local UI follow-up: user reported missing login/recovery controls. Chrome confirmed the shared DDEV page was still Google-primary and `coffee_journal_auth` was disabled. Enabled the auth module and set `user.settings:verify_mail` to true in the local DDEV database, then successfully rebuilt caches. Chrome confirmed visible email/username and password fields, Log in, Forgot password / Set up password, Create an account, and Continue with Google. Clicked the recovery link and verified the recovery form renders. These are local runtime changes only; no exported config, hosted environment, account password, or mail was changed by this check. Full Google OAuth/linking and invitation-readiness gates remain open.
