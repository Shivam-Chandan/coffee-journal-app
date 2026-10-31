# Headless migration execution record

## 2026-11-01 — fresh-account username/password browser verification

The user confirmed that Google sign-in is not required. Frontend acceptance now
uses Drupal username/email and password; OAuth callback integration is no longer
a frontend release blocker. Existing traditional Drupal provider configuration
is preserved.

Verified at 390px through the real localhost Vite → DDEV proxy:

- Created a new temporary, non-administrator Drupal account with a random
  password. It was provisioned locally for this test, not through a frontend
  registration screen.
- Signed in by **username**, with credentials delivered through the browser
  credential vault; no Google login or mocked network responses.
- Created a private coffee using the frontend form and its protected API.
- Reloaded the detail page and confirmed the saved entry persisted.
- Signed out through the frontend. The session became anonymous, local drafts
  were cleared, and opening the private detail URL showed the unavailable state.

Temporary account, owned entry, and browser credential profile were removed
after verification. Public registration, deployed Vercel session verification,
and the other outstanding migration features remain separate tasks.

## 2026-10-31 — all primary screens implemented against real Drupal APIs

Status: feature-complete for the agreed first-release screens, verified locally.
No Vercel or Acquia deployment has been performed.

### Backend bug found and fixed

Probing the write path revealed that saving any `coffee_bean` failed with
`Field field_is_public is unknown`. `brew_privacy_node_access_records()` read
that field on both bundles, but only `brew_recipe` has it in this
configuration. Beans could therefore never be created or edited, through the
new API or the existing Drupal UI. The hook now checks `hasField()` first and
beans remain owner-only. Verified by saving, inspecting the written grant rows,
and deleting a probe node.

### Added Drupal contracts

All responses are private/no-store, re-check entity and field access per
request, and keep the legacy endpoints intact for the existing Drupal UI.

- `GET /api/v1/session`, `/api/v1/form-options`
- `GET /api/v1/beans`, `/api/v1/beans/{uuid}`, `/api/v1/bean-choices`
- `GET /api/v1/feed`, `/api/v1/brews/{uuid}`
- `GET /api/v1/me/profile`, `/api/v1/me/brews`, `/api/v1/me/saved`
- `POST/PATCH/DELETE /api/v1/beans[/{uuid}]`, same for `/api/v1/brews`
- `PUT /api/v1/brews/{uuid}/visibility`
- `PUT|DELETE /api/v1/content/{uuid}/like` and `/bookmark`
- `POST /api/v1/auth/login`, `/logout`, `/password-reset`

Writes require CSRF plus a session, take ownership from the session rather than
the request body, run entity validation before saving, reject stale edits with
409 using the changed-time version, and deduplicate creates with an
account-scoped `Idempotency-Key`. Bean deletion refuses while brews reference
it rather than cascading. Engagement uses PUT/DELETE desired state, so a retry
cannot silently flip a like back off. Login resolves email identifiers through
the mail field only, fails closed on duplicates, preserves core IP and
per-account flood budgets, and returns one neutral message for unknown
accounts, wrong passwords and blocked accounts. Form metadata (labels, allowed
values, min/max, required) is read from Drupal field configuration, so the
frontend does not restate it.

### Added frontend screens

Login and password-reset request, journal list, bean detail, bean create/edit,
feed, brew detail, brew create/edit, add sheet, brew-method picker, profile with
Brews/Saved tabs, sign-out, plus delete and share confirmation sheets. All use
the approved visual system, keep the original navigation order, and are
route-split. Supporting work: typed API client with CSRF handling, Zod response
validation, account-scoped query keys, optimistic engagement with rollback,
focus-trapping sheets, accessible form controls, and account-scoped local drafts
that always distinguish "kept on this device" from a server save.

### Verification evidence

- Frontend: 20 tests pass (`npm test`), TypeScript clean, production build clean.
  Main bundle approximately 129KB gzip plus per-screen chunks under 2.5KB gzip.
- Generated Vercel output: **0 functions** (allowlist 0, hard ceiling 11).
- Backend: 7 kernel tests, 108 assertions pass on isolated SQLite fixtures,
  covering anonymous/owner/other-user isolation, cursor stability and
  validation, ownership-safe writes, idempotent replay, stale-edit conflict,
  reference rejection, delete protection, visibility revocation, and idempotent
  engagement. `phpcs` with Drupal/DrupalPractice is clean on all new files.
- Live HTTP checks against DDEV: email and username login both succeed; a wrong
  password and an unknown account return the same 401; writes without CSRF are
  rejected; invalid input returns per-field 422 messages; a stale version returns
  409; replaying an idempotency key returns the original record without creating
  a duplicate; deleting a referenced bean returns 409; a second signed-in account
  receives 404/403/422 for another user's private bean, brew edit, brew delete
  and bean reference, and sees no private bean through a public brew.
- Browser journey on a 390px viewport through the real Vite→DDEV proxy:
  sign in, create a coffee, open its detail, pick a brew method, create a shared
  brew, like and save it, see it in the feed and profile, view the saved tab, and
  sign out. After signing out the journal is guarded, the private bean returns
  its unavailable state, engagement controls disappear, and no drafts remain.
- Axe WCAG 2 A/AA: 0 violations on add sheet, bean detail, brew detail, feed,
  profile, saved tab and login, in light and dark themes.
- No horizontal overflow at 320px or 390px.

### Still outstanding

Photo upload and image delivery, Google sign-in through the frontend origin,
registration, public share HTML with Open Graph metadata, legacy `/node/{id}`
and `/my-coffees/{slug}` resolution, PWA installation and offline shell,
deployed external-rewrite and cookie verification, physical-device testing,
approved visual baselines, and production cutover with rollback rehearsal.

## 2026-10-31 — approved frontend visual refresh

The user requested a new font, icons, and modern native-app appearance, preserving
screen structure. This supersedes the original styling-only/pixel-parity direction.

- Added self-hosted variable DM Sans and a small inline SVG icon component.
- Refreshed light/dark tokens to forest/off-white and sage/charcoal.
- Kept navigation destinations/order, journal grid, feed stream, and account behavior.
- Added larger typography, borderless rounded cards, roast-colored decorative bean
  illustrations, numeric rating summaries, page hierarchy, and a safe-area dock.
- Updated loading/empty/error presentation and browser theme color.
- No API changes or fake production records. Populated visual checks used synthetic
  responses in an isolated browser session.
- Frontend tests: 8 pass. TypeScript/build pass; generated output remains 0 functions.
- Journal and feed light/dark automated WCAG A/AA checks: zero violations.
  Feed light contrast was corrected and rechecked. Empty-state checks pass.
- No overflow at 320px and 1280px; inspected 390px screenshots. Main JS remains
  approximately 125KB gzip, CSS approximately 4.2KB gzip; fonts are static assets.
- Physical-device and complete migrated-screen review remain future release gates.

## 2026-10-31 — first implementation increment

Status: local foundation implemented and verified; no Vercel/Acquia deployment.

### Baseline and decisions

- Local DDEV bootstraps Drupal **11.3.8**, PHP 8.3, MySQL 8.0. This corrects the
  older version listed in project context for the local environment only.
- Locally enabled: `coffee_journal_api`, `brew_privacy`, JSON:API, and Google
  social auth. `coffee_journal_auth` is not enabled. No account-policy changes made.
- Preserve Google and existing accounts. Native email/API login remains a later
  integration with the approved authentication plan, including flood controls.
- Use `frontend/` with its own lockfile and independent workflow. Existing Acquia
  deployment allowlisting already excludes this directory. The only shared pipeline
  change adds frontend-only paths to the push ignore list; existing build/deploy
  and user-owned security/auth changes were preserved.
- Baseline shell and styles come from the current theme working tree. A local
  anonymous feed screenshot was captured at 390px. Complete deterministic
  screenshot fixtures/approval, production baseline, and physical phones remain pending.
- Adopt zero-function static output and explicit backend rewrites. No gateway
  function is needed for the current read-only slice; auth feasibility is unproven.
- Hostname/callback configuration, protected-photo migration, and delete semantics
  remain unresolved before the corresponding write/upload features are implemented.

### Implemented

- React/TypeScript/Vite workspace with TanStack Router/Query and Tailwind 3.
- Existing token palette, light/dark persistence, top bar, Feed/Journal/Add/Profile/
  Saved order, two-column bean cards, and minimal feed cards.
- Route-split read-only feed/journal screens; explicit placeholders for later features.
- Runtime response validation, abortable same-origin requests, error/empty/loading
  states, account-keyed private queries, and cancellation/removal on observed changes.
- Additive GET session/beans/feed controller/routes in the existing API module.
  Owner/public filtering, field/entity access, published-only lists, bounded stable
  ID cursors, and private/CDN no-store headers. No writes or relationship inclusion.
- Generated Vercel Build Output v3, explicit API-before-SPA routing and caching,
  output inspection with empty function allowlist and independent hard ceiling 11.
- Independent frontend/API CI definition and project guidance update.

### Verification evidence

- Node 22.23.2; `npm ci` succeeds with the committed lockfile.
- `npm run typecheck` and `npm test`: **8 frontend/build-boundary tests pass**.
- `DRUPAL_ORIGIN=https://eeschandan1dev.prod.acquia-sites.com npm run build:vercel`:
  passes, **0 functions** in generated output. Main JS approximately **124KB gzip**,
  CSS approximately **3.3KB gzip**, with separate feed/journal chunks.
- Isolated SQLite Drupal kernel suite: **2 tests, 31 assertions pass**, exercising
  anonymous/owner/unrelated-user isolation, public-only feed, cursor validation,
  pagination across concurrent insertion, and cache headers.
- Local `ddev drush cr` and controller PHP syntax check pass.
- Drupal/DrupalPractice coding standards pass for the new controller and kernel suite.
- Browser through the real Vite→DDEV proxy: session 200 JSON, anonymous beans 401
  JSON, feed 200 JSON, invalid cursor 400 JSON; all return private/no-store and CDN
  no-store. Unknown legacy API path reaches Drupal's 404 (currently HTML), not SPA
  fallback. A versioned JSON error fallback remains part of API contract completion.
- Browser on Chromium at 320px/390px and desktop 1280px: no horizontal overflow;
  theme switching changes the expected background tokens. Real anonymous screens
  and synthetic authenticated journal fixtures were checked separately. Authenticated
  fixture rendering is not evidence of a real browser login.
- Axe WCAG 2 A/AA checks on the journal fixture: **0 violations** in light/dark
  after correcting rating semantics and navigation contrast. Decorative glyph
  contrast remains a manual-review item. This is not a full accessibility sign-off.
- Screenshots captured in the agent temporary directory: `headless-baseline-feed.png`,
  `headless-react-feed.png`, `headless-journal-fixture.png`, `headless-journal-dark.png`.
  They are local review evidence, not committed golden snapshots.
- Initial dependency review upgraded Vite/PostCSS/Vitest/Zod to patched releases.
  npm currently reports one low-severity transitive esbuild Windows development-server
  advisory; it is not bundled in the deployed browser application. Review toolchain
  updates before a shared development-server rollout.

### Next dependency gate

Finish Phase 0 fixtures and configure a Vercel preview/stable staging hostname, then
prove real login/session rotation/CSRF write/logout through that origin. Keep the
main migration tasks open until their full exit gates pass. Session/OAuth, writes,
profile/saved functionality, actual images, share HTML, legacy links, PWA/drafts,
full visual parity, physical-device testing, CI execution on GitHub, and production
cutover have not been completed.
