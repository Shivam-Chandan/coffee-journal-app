# Critical security implementation plan

Status: implementation in progress; regression suites and local DDEV API smoke checks pass. Real outbound-transport, delivery-pipeline, administrator, and rollout checks remain pending. Not deployed to Cloud.
Prepared: 2026-10-30.

## Handoff and scope

The user requested an implementation plan for the critical findings in an architecture review, then requested that it be saved for another agent. This document records that plan; saving it does not authorize credential rotation, deployment, history rewriting, or changes to account policy.

Read `CLAUDE.md` and any applicable `AGENTS.md` before implementation. Keep business logic in custom modules and presentation in the custom theme. No core/contrib changes. On 2026-10-30 the user explicitly approved the scope exception for workflow, repository/ignore rules, runtime-settings, exported-config, execution-plan updates, and removal of tracked credential files. Credential rotation, history rewriting, and deployment remain separately authorized administrator work.

Checked implementation items below describe source changes unless a runtime result is recorded explicitly. The regression suites and local DDEV checks now run successfully; scanner jobs, the complete artifact build, and Cloud environment checks have not run.

Google authentication migration is parked. Preserve the current `social_auth` / `social_auth_google` flow.

### Evidence and corrections

- `git ls-files -- ssh-keys-github salt.txt` confirms both files are tracked. Whether the key is still authorized and whether the salt is effective in production require verification. Do not describe either as confirmed live solely from its presence in Git.
- `GeminiService::fetchPageHtml()` accepts a URL and follows redirects without destination checks.
- Gemini and legacy Places services place API keys in request URLs and propagate upstream exception messages. Trace both client responses and logs during implementation.
- There are six custom POST endpoints in scope, not eight. Missing CSRF checks are confirmed in the reviewed routes; practical exploitability depends on cookie/browser behavior.
- `config/default/user.settings.yml` currently has `register: visitors` and `verify_mail: false`.
- Earlier architecture-review conclusions are inputs to verify, not a substitute for checking current source and deployed controls. Do not assume environment approvals, credentials, or production settings from repository code alone.

## Delivery sequence

1. Contain credential exposure and secure packaging.
2. Harden outbound requests, errors, CSRF, usage limits, and content access.
3. Run focused regression tests, gate deployment, and roll out progressively.

## 1. Credential containment and deployment packaging

Owner: developer plus GitHub/Acquia administrator.

- [ ] Identify where the tracked SSH key is authorized using its public fingerprint without printing private-key contents.
- [ ] Revoke any authorized exposed key and provision a replacement through the appropriate secret store.
- [ ] Confirm the effective hash-salt source in each environment without displaying its value.
- [ ] Replace an exposed effective salt with an environment-specific secret outside version control. Schedule the change: signed links and tokens may become invalid.
- [ ] Rotate Gemini/Places credentials if exposure through responses or logs is confirmed or cannot be ruled out. Restrict replacements to their required APIs.
- [x] Remove credential files from tracked source and prevent their reintroduction (working-tree deletions, ignore rules, and blocking current-tree scanning added; no commit created).
- [x] Replace whole-workspace artifact uploads with an explicit deployment file list. Preserve required Drupal scaffold, dependencies, and deployment hooks (build validation pending).
- [x] Add secret detection and artifact inspection as blocking delivery checks (workflow wiring added; execution pending).
- [ ] Coordinate separately authorized history cleanup across affected remotes and removal of accessible old artifacts. Rotate first: history deletion does not revoke credentials.

Relevant paths: `.github/workflows/build.yml`, `.github/workflows/deploy.yml`, `docroot/sites/default/settings/`, root credential files and ignore rules.

Acceptance:
- Old authorized SSH credentials no longer grant access.
- Runtime secrets come from approved secret storage.
- New artifacts contain no private keys or secret salt files.
- Secret-scanning failures block delivery.

## 2. Safe outbound page fetching

Primary path: `docroot/modules/custom/coffee_journal_api/src/Service/GeminiService.php`.

- [x] Introduce an injectable safe-page-fetching service and wire it through the module's service definitions.
- [x] Accept HTTPS product URLs only; reject embedded credentials and restrict ports.
- [x] Resolve and validate all destination addresses. Reject loopback, private, link-local, reserved, and equivalent IPv4-mapped IPv6 destinations.
- [x] Bind connections to validated addresses while preserving TLS hostname verification. A validation lookup followed by an independent connection lookup is insufficient against DNS rebinding.
- [x] Disable automatic redirects. Follow a small bounded number manually, validating and binding each new destination.
- [x] Enforce connection/read timeouts, a streamed response-size limit, and supported content types. Do not download an unbounded body before checking its size.
- [x] Return safe validation errors for rejected destinations.

Acceptance:
- Private/internal targets, redirect bypasses, and DNS-rebinding fixtures fail before connection.
- Valid public HTTPS product pages remain supported.
- Oversized or stalled responses terminate within configured limits.

## 3. Secret-safe error handling

Paths under `docroot/modules/custom/coffee_journal_api/src/`:
- `Service/GeminiService.php`
- `Service/GooglePlacesService.php`
- `Controller/GeminiController.php`
- `Controller/PlacesController.php`

- [x] Replace raw exception messages in API responses with stable codes and user-safe messages.
- [x] Log sanitized diagnostics only: provider, status, correlation ID, error category. Avoid secret-bearing URLs, headers, response bodies, and exception chains.
- [x] Move Gemini authentication to its supported API-key header.
- [x] Preserve the legacy Places endpoint's supported authentication mechanism unless explicitly migrating it. Do not assume that endpoint accepts Gemini's header mechanism.
- [x] Audit all exception/logging paths for credential disclosure (source review of the specified services/controllers complete; runtime/middleware log capture pending).

Acceptance:
- Mocked provider failures expose no dummy secrets in responses or captured logs.
- Invalid input, unavailable services, and quota exhaustion have distinguishable safe messages.

## 4. CSRF protection

Paths: routing files in `coffee_journal_api`, `coffee_journal_likes`, and `coffee_journal_bookmark`; `docroot/themes/custom/coffee_journal/js/app.js`.

Protect these six POST endpoints:
- `/api/scrape-coffee`
- `/api/places/search`
- `/api/brew/{node}/publish`
- `/api/brew/{node}/photo`
- `/api/likes/{node}`
- `/api/bookmarks/{node}`

- [x] Add Drupal's `_csrf_request_header_token: 'TRUE'` route requirement alongside authentication requirements.
- [x] Add a shared frontend request helper that obtains the current session token from `/session/token` and sends `X-CSRF-Token`.
- [x] Apply the helper to JSON and multipart requests without breaking browser-generated multipart boundaries.
- [x] Handle expired sessions/rejected requests visibly and reverse optimistic UI updates on failure. Avoid automatic replay of mutations that might already have succeeded.
- [x] Preserve Drupal Form API's existing token handling for normal forms.

Acceptance:
- Missing/invalid tokens fail before side effects or paid API calls.
- Valid authenticated requests work; anonymous requests remain denied.
- Photo uploads and form/drawer flows still work.

## 5. Paid API usage limits

Paths: Gemini/Places controllers, module service definitions, and module configuration.

- [x] Introduce a shared limiter using Drupal's Flood API with configurable per-user and secondary IP limits.
- [x] Enforce limits before outbound requests; count failed attempts too.
- [x] Return HTTP 429 with `Retry-After` and a useful frontend retry message.
- [ ] Configure provider-side quotas as a spending backstop. Account throttles alone cannot prevent multi-account abuse.

Limits approved by the user on 2026-10-30. Secondary per-IP limits are five times the per-user limits:

| Endpoint | Per minute/user | Per day/user |
| --- | ---: | ---: |
| Coffee scraping | 5 | 50 |
| Places search | 20 | 200 |

Acceptance:
- Over-limit requests make no upstream calls.
- Limits recover after their configured windows.
- Users are accounted for independently, with secondary IP controls tested separately.

## 6. Content access and verified registration

Paths: like/bookmark controllers and account configuration.

- [x] Require node view access and an eligible bundle before likes or bookmarks are allowed.
- [x] Filter list/count responses by current node access, including items that became private after bookmarking.
- [x] Bound the number of IDs accepted by count requests.
- [x] Prevent personalized responses from being shared through caches.
- [ ] After policy confirmation, enable email verification for native registration through Drupal configuration and export the change.
- [ ] Verify outbound mail delivery and first-time Google account creation.
- [ ] Preserve Google signup; do not change registration to administrator-only without testing its effects on OAuth provisioning.

Acceptance:
- User A cannot interact with or retrieve engagement data for User B's inaccessible content.
- Accessible public content remains usable.
- Native registration requires verification; Google registration/login remain functional.

## Validation and rollout

- [x] Add focused automated tests for unsafe URLs, redirects, DNS changes, response-size limits, and timeouts (mocked transport; real-cURL fixtures still pending).
- [x] Test provider failures with dummy secrets and assert that responses/logs contain none.
- [x] Test authenticated/anonymous requests with valid, missing, and invalid CSRF tokens (unit tests and local DDEV session-cookie requests).
- [x] Test rate-limit enforcement without paid API calls (unit tests and actual local Flood backend).
- [x] Test two-user private-content access, including visibility changes after interactions (unit tests and actual local Drupal grants).
- [ ] Test native registration and Google-auth regression paths with controlled test identities.
- [ ] Make these checks a deployment prerequisite; verify the pipeline actually depends on their success.
- [ ] After explicit deployment authorization, roll out dev → test → production with verified approval gates.
- [ ] Rebuild Drupal caches after routing/configuration changes and verify the main user flows at each stage.

Use mocked upstreams and isolated fixtures for adversarial cases. Do not probe production/internal metadata services to test SSRF or consume real paid quota for negative tests.

Rollback: retain the previous application artifact, but never restore exposed credentials. If scraper compatibility blocks rollout, disable that endpoint temporarily while retaining security fixes.

## Decisions and dependencies for the next agent

1. Scope exception granted on 2026-10-30 for repository/workflow/settings/exported-config edits and this execution log.
2. Proposed rate limits approved, including IP limits at five times the user limits. Native email-verification policy explicitly deferred; account configuration and Google authentication flow are preserved.
3. Identify the administrator responsible for credential revocation, replacement, and history cleanup.
4. Inspect current Git status and source before edits; preserve unrelated user work.
5. Keep this checklist current and record tests, blockers, deployment state, and any deviations here. Do not mark deployment-dependent items complete based only on code changes.

## Execution log

- 2026-10-30: Plan saved for handoff. No application code, credentials, account policies, workflows, or environments changed as part of saving this document.
- 2026-10-30: Execution started following user instruction. Read the project rules; no `AGENTS.md` was found by the workspace search. Preserved pre-existing release-drafter edits and unrelated untracked files, including a separately appearing email/password migration plan.
- 2026-10-30: User approved the scope exception and rate-limit defaults through clarification questions; deferred native registration policy. No credential-rotation, deployment, or history-rewriting authorization was given.
- 2026-10-30: Recorded the exposed deployment key's public fingerprint without reading/printing private-key contents: `SHA256:2rhetDgHFfkmpAWj4IL+Tifu+VL34T0R6E612Z9vg+k` (`github-actions-deploy`, ED25519). Removed root `ssh-keys-github` and `salt.txt` from the working tree. Authorization status of the key is unknown; revocation/replacement is still required if authorized. Older Git revisions and artifacts have not been cleaned.
- 2026-10-30: Found that `vendor/acquia/drupal-recommended-settings/settings/misc.settings.php` falls back to root `salt.txt` if no prior salt is present. Added early `DRUPAL_HASH_SALT` support in `settings.php`; Acquia platform/secret settings may also supply the effective salt. **Before deployment, verify the effective source in every environment and provision an environment-specific secret where needed.** No salt values or environment variables were read or changed. Local/non-Acquia bootstraps now require an external salt rather than the removed tracked file.
- 2026-10-30: Added `SafePageFetcher`, `PublicAddressResolver`, a dedicated cURL client factory, and a write-bounded response sink. Policy: HTTPS/443 only; every DNS answer must be public; connect to one validated answer with `CURLOPT_RESOLVE` while keeping hostname TLS verification; proxy disabled; fresh connections; at most three redirects; five-second connect timeout, fifteen-second total timeout per hop, five-second low-speed timeout; 2 MiB maximum per response; HTML/XHTML only and identity encoding. Private/mapped/transition/reserved address space is rejected conservatively. PHP's cURL extension is required and the fetcher fails closed without it.
- 2026-10-30: Added safe provider error codes, correlation IDs, and allowlisted diagnostic metadata. Removed page URLs, upstream exception messages/chains, and extracted content from custom provider logs. Gemini uses `x-goog-api-key`; legacy Places query authentication is retained and redirects are disabled for both providers. Provider quota failures are distinguishable (`provider_quota`, HTTP 503); local account/IP throttling uses HTTP 429.
- 2026-10-30: Protected all six POST routes and added `Drupal.coffeeJournalApiRequest` for custom API mutations. Token acquisition is shared; rejected mutations are not replayed. Multipart boundaries remain browser-generated. Likes/bookmarks now reverse optimistic state on HTTP/network failures, restore like counts, show errors, and prevent duplicate in-flight clicks. There is no Places search caller in the current custom JS; future callers must use the shared helper.
- 2026-10-30: Added configurable Flood limits and identity locks around check/reserve pairs. User limits: scraping 5/minute and 50/day; Places 20/minute and 200/day. IP limits: scraping 25/minute and 250/day; Places 100/minute and 1000/day. Attempts are reserved before the provider call, including calls that fail; rejected calls do not proceed upstream. `Retry-After` is conservatively the failed window (60 or 86400 seconds; one second for lock contention). Defaults exist in module install config, exported config, and runtime fallback for existing installations. The export file was authored directly because no local Drupal runtime is available; validate it through import/export before rollout.
- 2026-10-30: Like/bookmark toggles and ID lists, plus like counts, now enforce node view access and the `coffee_bean`/`brew_recipe` bundle allowlist. Count requests accept at most 100 positive IDs. Access is checked again when listing existing interactions. Engagement responses are `private, no-store`.
- 2026-10-30: Added an explicit artifact manifest (`.github/deployment-files.txt`), inspected-staging-tree-only uploads, path/symlink inspection, redacted Gitleaks scans of current tracked source and staged artifacts, and a reusable security regression workflow. The inspected tree is archived as `deploy.tar` to preserve executable Cloud hooks and internal dependency links through GitHub artifact storage. Build depends on the security workflow; artifact inspection/scanning precede upload; deployment depends on build. PRs run security checks too. Security script changes now trigger the pipeline. The deploy job now extracts and mirrors the inspected artifact with deletion into a separate checkout so previous files are not restored, preserves history, and uses a fast-forward push. Manual test/prod rollout also passes through dev. Actual job execution and GitHub environment approval settings remain unverified.
- 2026-10-30: Added PHP regression fixtures for address policy, mixed DNS answers, unsafe URLs, redirect rebinding and bounds, cURL pinning/timeout options, size/content-type limits, sanitized provider errors/logs, quota codes, limiter identity/windows/contention, content access/visibility changes, and route declarations against Drupal's header-token checker. Added Node request-helper/multipart/no-replay/optimistic-rollback tests and Python artifact-inspection fixtures. CI test execution is designed for a network-disabled, read-only container with scratch-only writable storage and CPU/memory/process/file-size/disk/wall-clock bounds.
- 2026-10-30: Available local check: `git diff --check` passed. PHP, PHPUnit, Node.js, and Docker are not available on this shell's PATH, so no PHP/JS/Python target-code tests, artifact build, scanner execution, config import/export, or `drush cr` ran. Python/Ruby are present, but a complete OS-enforced test sandbox with all required resource/storage controls was not established; fixtures were not executed unsandboxed. No paid API calls, internal-network probes, deployed-site tests, commits, pushes, or deployments were performed.
- 2026-10-30: User requested DDEV startup and testing. Located DDEV, Docker, PHP, and Node.js outside the harness's restricted PATH (`/opt/homebrew/bin` and `/usr/local/bin`). Docker Desktop was already running. Created a local `brewtal` DDEV project with PHP 8.3, MySQL 8.0, Node.js 20, DDEV-only database overrides, and an explicit dummy local salt. Paid-provider keys are empty in this local environment. The live URL is `https://brewtal.ddev.site`. The only application bootstrap addition is a DDEV-gated include of `.ddev/settings.php`, after Acquia's local database defaults. `.ddev` is absent from the deployment manifest.
- 2026-10-30: DDEV startup succeeded. Downloading its optional MySQL client failed certificate-chain validation; the web/database services remained healthy. The available MySQL CLI also encountered self-signed-certificate validation during Drush's optional database drop/create step; PDO connected successfully to the already-created empty database. TLS verification was not disabled for downloads.
- 2026-10-30: User explicitly authorized a fresh local fixture installation. Direct `site:install --existing-config` was rejected by Drupal because the exported `standard` profile has an install hook. Installed standard normally, aligned the local site's UUID, removed newly created shortcut fixtures, and imported the exported configuration. The first import encountered standard-profile field-deletion ordering errors; after a cache rebuild, a second import succeeded. No exported config was overwritten or Cloud database/files fetched. Existing schema warnings and five unrelated baseline configuration normalizations remain (`core.entity_form_display.user.user.default`, `editor.editor.basic_html`, `editor.editor.full_html`, `field.field.node.brew_recipe.field_photo`, `views.settings`). The new API limiter configuration imported successfully and is not among those differences.
- 2026-10-30: Ran regression suites in separate network-disabled, read-only DDEV-image containers, with only required source/dependency mounts, dummy/allowlisted environment values, scratch-only writable storage, and bounded CPU/memory/process/file-size/disk/wall-clock resources. First PHP run found a missing config object in the lock-contention fixture; corrected the stub. Deprecation output then identified an undeclared dynamic `$stream` property in `BoundedResponseStream`; declared it explicitly. Converted PHPUnit group metadata to attributes and enabled deprecation failures in CI. Final PHP run on PHP 8.3.32: **67 tests, 288 assertions, no failures or deprecations**. Node suite: **6 tests passed**. Python artifact-inspection suite: **4 tests passed**.
- 2026-10-30: Local Drupal 11.3.8 bootstrap reports `Successful`, database reports `Connected`, and `drush cache:rebuild` succeeds. Created two dummy authenticated accounts (UIDs 2/3) and three brew fixtures (NIDs 1/2/3), without production identities or data. Initial cross-user testing exposed the standard profile's residual `node_access` nid=0/all grant after importing the privacy module. Rebuilding content permissions removed that permissive fixture setup state; the local setup script now initializes grants before testing. **Verify deployed grant state/rebuild requirements before rollout; module code alone is not evidence that an existing environment's grants are correct.**
- 2026-10-30: Live DDEV browser/API run passed **59 checks**: anonymous denial and missing/invalid header-token denial on all six endpoints; authenticated requests with a valid session token; safe unavailable-provider responses without paid requests; successful brew publishing, real multipart photo upload, like/bookmark mutations; cross-user private-node denial; count input bounds; filtered counts and private/no-store headers; local Flood rejection after 5 scraping or 20 Places attempts with `Retry-After: 60`. A follow-up confirmed that existing likes/bookmarks and counts disappear after a public fixture becomes private, and further mutations return 403. A second user's separate browser session confirmed owner access to private content and independently accounted user limits. The PHP limiter suite covers secondary IP policy, windows/recovery, and lock contention with mocked Flood storage; browser tests exercise the real per-user Flood backend.
- 2026-10-30: Browser drawer smoke check opened the Add sheet and coffee form; the normal FAPI token was present, the shared request helper loaded, and Auto-fill displayed the safe unavailable-service message through the live token-protected endpoint. No full coffee-form save or Google OAuth/mail regression was performed. Closed the dedicated test-browser sessions after testing; DDEV remains running for the user.
- 2026-10-30: Final `git diff --check` passed. Additional native-auth module/theme edits appeared concurrently during this session; they were preserved and are outside this security test pass. No commits, pushes, Cloud deployments, real credential changes, or paid API requests were made.

### Next execution steps / blockers

1. Run the new reusable security workflow in an authorized CI context. Local PHP/Node/Python regressions now pass; the complete CI toolchain image and scanner jobs remain unrun. Build and inspect the complete production artifact, including scaffold, dependencies, hooks, and settings includes.
2. Add/run isolated real-cURL fixtures for stalled reads, TLS hostname verification, and pinning across a DNS change; current outbound-transport tests still mock cURL behavior and assert options. Local router/session-cookie checks now pass for all six endpoints.
3. Local real-grant two-user/visibility, multipart upload, publish, drawer, no-store, and per-user Flood checks now pass. Verify full FAPI save flows, real shared-cache behavior, trusted-proxy/IP attribution, rate-limit recovery, and deployed Flood behavior in dev/test. Verify effective node grants and rebuild them where needed before relying on privacy checks in any rollout environment.
4. Assign the GitHub/Acquia administrator for key authorization checks, revocation/replacement, effective salt verification/provisioning, provider credential exposure assessment/rotation, provider quotas, and separately authorized old-artifact/history cleanup. Do not deploy the salt-file removal before its replacement source is verified.
5. Confirm GitHub required reviewers/approval gates independently; runtime evidence cannot be inferred from the YAML. The existing Cloud hook was preserved and needs end-to-end verification, including successful config import and cache rebuild.
6. Native email verification remains a deferred policy decision. Arrange controlled mail delivery and existing Google signup/login regressions before any registration-policy change.
7. Obtain deployment authorization only after the above checks, then progress dev → test → production. Rebuild Drupal caches for service/routing/config changes. Never restore the removed credential files during rollback.
