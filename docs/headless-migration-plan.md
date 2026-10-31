# Headless Drupal and mobile frontend migration plan

Date: 2026-10-30

Status: initial headless application deployed to production and smoke-tested.
See the [production release record](headless-migration/production-release.md)
and [execution evidence](headless-migration/execution.md) for verification and
remaining migration work.

Change ID: `migrate-headless-frontend`

## 1. Outcome and agreed direction

**Authentication amendment (2026-11-01):** The user confirmed that Google login
is not needed for the frontend. Use username/email and password with Drupal
sessions. Test with temporary local accounts. Google/OAuth frontend integration
is removed from the release gates below; preserve existing backend provider
configuration for the traditional UI. Frontend account registration remains a
separate outstanding feature.

**Design amendment (2026-10-31):** After reviewing the initial styling port, the
user requested a modern frontend visual refresh while retaining the screen
structure. The original pixel-parity requirement below is superseded for the
frontend by DM Sans typography, SVG icons, sage/charcoal tokens, readable rounded
cards, and a compact bottom dock. Keep workflow/route parity and light/dark support;
review screenshots against the refreshed frontend design, not Drupal pixels.

Move the user-facing Coffee Journal application to a JavaScript frontend on Vercel while retaining Drupal on Acquia as the backend. The first release preserves the existing UI styling and workflows. It is a frontend migration, not a visual redesign or database migration.

Selected stack:

- React and TypeScript for the application UI.
- Vite for development and static production builds.
- TanStack Router for client-side routing and persistent layouts.
- TanStack Query for server data, caching, and mutations.
- Existing Tailwind CSS 3 styling and `--cj-*` design tokens.
- Drupal sessions, entities, access control, files, integrations, and queues.
- An installable progressive web app (PWA), with local drafts in the first release.

**Vercel target: zero deployed functions; one explicitly approved gateway function if the authentication spike proves it necessary. The absolute deployment ceiling is 11 functions.** Routes, screens, and Drupal endpoints do not each require a Vercel function.

This plan is accompanied by an [implementation checklist](headless-migration/tasks.md), [proposal summary](headless-migration/proposal.md), and [acceptance requirements](headless-migration/specs/headless-app/spec-delta.md).

## 2. Why

The current app has mobile-oriented styling but its frontend is tightly coupled to Drupal-rendered pages and forms. A persistent React application can retain navigation state, display cached screens quickly, manage drafts, and provide consistent loading/error behavior without fetching and extracting Drupal HTML.

React + Vite + TanStack Router/Query fits the interactive journal, brew editor, feed, and profile workflows. Astro's content-first rendering model offers less benefit when most primary screens are interactive and personalized. TanStack Start is deferred because server rendering is not currently required for the application shell. Revisit that choice if search-indexable public content becomes a primary launch requirement.

Headless rendering alone does not guarantee better performance. Useful-content loading, API latency, JavaScript size, image sizing, and phone interaction behavior must be measured.

## 3. What changes

1. Introduce a static React application with the current visual identity.
2. Add versioned Drupal APIs for workflows currently implemented through rendered forms.
3. Establish same-origin browser access to Drupal through Vercel external rewrites.
4. Adapt existing authentication to the frontend while preserving Drupal identities.
5. Replace Drupal JavaScript behaviors with React components, hooks, and mutations.
6. Add mobile/browser verification, visual parity checks, and deployment function-count checks.
7. Pilot the frontend alongside the existing Drupal UI before production cutover.

### First-release scope

- Session/login/logout and required account recovery/registration flows.
- Journal listing, sorting/filtering, bean detail, create/edit/delete, and existing finished/reorder state controls.
- Brew detail, create/edit/delete, method selection, and creation from a bean.
- Public feed, likes, bookmarks, saved items, and profile tabs/statistics.
- Scraping-based autofill, Places search, photo upload, and brew sharing.
- Current light/dark appearance, navigation, drawers, cards, ratings, and badges.
- Installable shell, offline message, and account-scoped local drafts.
- Existing-link compatibility and public share metadata.

Verify this inventory against the running app in Phase 0. Existing behavior is the parity baseline; proposed endpoints below are not evidence that a feature already exists.

### Deferred scope

- Visual redesign, new navigation taxonomy, or replacement component-library aesthetics.
- Native App Store/Play Store packaging, push notifications, and native-only integrations.
- Offline mutation queues, cross-device draft synchronization, and conflict merging.
- Replacing Drupal accounts/database/files with another backend.
- General frontend SSR, real-time subscriptions, and a new search platform.

## 4. Source-grounded starting point

These observations describe the working tree reviewed on 2026-10-30, not verified production behavior. There is existing uncommitted authentication, security, and pipeline work; implementation must inspect its latest state before editing.

| Area | Source | Consequence |
|---|---|---|
| Theme CSS | `docroot/themes/custom/coffee_journal/src/css/app.css` | Reuse authored CSS, custom properties, and light-mode overrides |
| Tailwind | Theme `package.json` and `tailwind.config.js` | Start with Tailwind 3; update content scanning for TS/TSX |
| Shell | `templates/page/page.html.twig` | Port top bar, Feed/Journal/Add/Profile/Saved navigation, and global drawer |
| Frontend behavior | Theme `js/app.js` | Replace HTML extraction, `Drupal.behaviors`, and DOM event wiring |
| JSON:API | `config/default/jsonapi.settings.yml` | Currently read-only; do not assume entity writes are available |
| Feed | `coffee_journal_api/src/Controller/FeedController.php` | Initial feed is a render array; provide a consistent JSON feed contract |
| User state | `coffee_journal_api/src/Controller/UserStateController.php` | Existing `/api/user/me` is a starting point for session/profile data |
| Auth | `coffee_journal_auth/README.md` | Email lookup currently applies to rendered login, not JSON authentication |
| Privacy | `brew_privacy/brew_privacy.module` | Preserve owner/public node grants for beans and brews |
| Sharing/uploads | `BrewShareController.php` | Existing POST endpoints, 5MB upload cap, and public file storage need explicit handling |
| Engagement | `coffee_journal_likes` and `coffee_journal_bookmark` | Reuse repository logic and access checks |
| Paid integrations | `coffee_journal_api` services | Keep credentials, safe fetching, provider limits, and errors in Drupal |

Related plans:

- [Email/password migration](email-password-auth-migration-plan.md).
- [Security implementation](critical-security-implementation-plan.md).
- [GitHub Actions/Acquia setup](github-actions-acquia-setup.md).

The email/password plan proposes eventually retiring Google; the security plan says that migration is parked and Google must be preserved. Resolve the effective rollout policy in Phase 0. Default for this migration: preserve enabled login methods, make the frontend compatible with the approved email flow, and do not retire Google as a side effect.

## 5. Architecture and boundaries

```text
Browser / installed PWA
  |
  +--> Vercel static assets: HTML, JS, CSS, manifest, service worker
  |
  +--> app origin /api/*, approved auth paths, /session/token
           |
           +--> Vercel external rewrite --> Drupal on Acquia
                                               |
                                               +--> Sessions and permissions
                                               +--> Entities and database
                                               +--> Files and derivatives
                                               +--> Gemini / Places
                                               +--> Queues and email
```

Frontend responsibility: rendering, navigation, local form state, user feedback, local drafts, and non-authoritative client validation.

Drupal responsibility: identity, authorization, authoritative validation, persistence, business rules, integration secrets, provider usage controls, and background jobs. Frontend route guards are a UX mechanism, not access control.

Keep Drupal administration on its backend hostname. Preserve the traditional UI during the pilot. Use reusable services/entity constraints so the old Drupal forms and new JSON controllers apply the same rules.

### Repository layout

Proposed default: one repository with a dedicated `frontend/` workspace and independent builds:

```text
frontend/
  package.json
  package-lock.json
  vite.config.ts
  vercel.json
  index.html
  public/                 # manifest, icons, static assets
  src/
    app/                  # router, providers, bootstrap
    routes/               # route declarations and screen boundaries
    components/           # shell, drawer, reusable controls
    features/             # auth, beans, brews, feed, profile, saved
    api/                  # transport, generated types, mapping
    styles/               # migrated theme source and tokens
    lib/                  # draft storage, formatting, utilities
  tests/                  # browser flows, visual fixtures, unit tests
docroot/modules/custom/   # Drupal API/business logic
docs/                     # architecture and execution record
```

Current `CLAUDE.md` assumes traditional Drupal and restricts implementation paths. Before creating the workspace, update the project guidance and record the approved scope for frontend, exported configuration, settings, and workflow changes. Keep the no-core/contrib-edit rule and Drupal business-logic separation. Do not apply PHP/Twig rendering rules to React components.

Vercel builds only `frontend/`. Acquia continues building Drupal; exclude frontend source, dependencies, and Vercel output from the deployment artifact. Coordinate workflow edits with existing uncommitted pipeline work.

## 6. Same-styling UI migration

### Visual baseline

Before porting, capture the current deployed/intended UI using stable fixture data:

- Login, journal, bean detail, bean form, brew form/detail, feed, profile, saved tab, and share sheet.
- Light and dark themes.
- Empty, populated, validation-error, loading, and long-content states.
- 320px, 390px, 430px, 768px, and 1280px widths.
- Actual iOS Safari and Android Chrome keyboard/safe-area behavior.

Record which environment and revision are the baseline. Reconcile source/deployed differences rather than unintentionally combining different designs. Fix fixture timestamps, images, and content for meaningful screenshot comparisons.

### CSS strategy

1. Copy authored `src/css/app.css` and relevant native-auth/profile styles into the frontend as the initial source baseline. Rebuild CSS; do not maintain copied minified `coffee-theme.css` as source.
2. Preserve `--cj-*` custom properties, warm palette, orange accent, spacing, radii, card geometry, and existing font appearance.
3. Preserve `.light-mode` semantics and the existing `cj-theme` preference key. Apply the preference before first paint. Preferences cannot be read automatically across different origins; retain the public hostname where practical and otherwise accept a one-time reset.
4. Keep Tailwind 3 for this migration. Scan React TS/TSX files and use explicit class maps for dynamic variants so production builds retain required utilities.
5. Retain useful `cj-*` selectors during the port. Replace Drupal-only wrappers/selectors deliberately and check their visual effects.
6. Audit duplicated global selectors, Drupal toolbar offsets, and dependencies on generated form markup.
7. Preserve Inter styling; evaluate self-hosting/subsetting the existing font to remove the blocking external CSS import without changing the visual identity.
8. Split CSS into tokens/base/layout/components after parity is established. Do not combine a Tailwind major-version upgrade with the migration.

Accessibility fixes, safe-area padding, keyboard handling, and missing loading/error states are allowed targeted improvements. Record intentional visual deviations in the parity review.

### Template-to-component mapping

Paths below are relative to `docroot/themes/custom/coffee_journal/` unless noted.

| Current source | React destination |
|---|---|
| `templates/page/page.html.twig` | `AppShell`, `TopBar`, `BottomNav`, `StatusMessages`, `Drawer` |
| `templates/coffee-journal-sidebar-nav.html.twig` | Existing desktop navigation where active |
| `templates/views/views-view--coffee-journal.html.twig` | `JournalPage`, `JournalFilters`, list/empty states |
| `templates/node/node--coffee-bean*.html.twig` | `BeanCard`, `BeanDetail` |
| `templates/form/node-coffee-bean*-form.html.twig` | `BeanForm`, ratings, roast selector, autofill |
| `templates/node/node--brew-recipe*.html.twig` | `BrewCard`, `BrewDetail` |
| `templates/form/node-brew-recipe*-form.html.twig` | `BrewForm` with selected bean/method |
| `templates/feed/feed-page.html.twig` | `FeedPage`, paginated feed cards |
| `templates/sheet/*.html.twig` | `AddSheet`, `BrewMethodPicker`, `ShareSheet` |
| `templates/user/page--user--login.html.twig` | `LoginPage` and approved account flows |
| `coffee_journal_access/templates/profile-page.html.twig` (module) | `ProfilePage`, statistics, posts/saved tabs |

### Behavior mapping

| Existing behavior in `app.js` | Replacement |
|---|---|
| `cjThemeToggle` | Theme provider and pre-paint preference initialization |
| `cjAddDrawer` | Accessible controlled drawer with route/back integration |
| `cjFormAutofill`, `cjFormScraper` | Typed mutation and explicit form-value mapping |
| `cjSort` | Validated URL search state and query keys |
| `cjRatingInputs`, `cjSegmentedRoast` | Controlled accessible inputs |
| `cjGlobalFeed` | Cursor-based infinite query and intersection observer |
| `cjAddRecipeFromBean` | Typed navigation carrying a validated bean reference |
| `cjBottomNav` | Route-aware active state |
| `cjLike`, `cjBookmark` | Desired-state mutation, optimistic update, rollback |
| `cjSharePhoto`, `cjShareBrew` | Upload/publish state machine with retry |

Do not run the old DOM-mutating `app.js` inside React. Do not fetch Drupal form HTML and insert it into React. Port formatting intentionally: shared domain calculations belong in Drupal services, while display formatting belongs in frontend helpers.

## 7. Routing and app state

Keep familiar routes wherever practical. Finalize the inventory before implementation:

| Frontend route | Purpose |
|---|---|
| `/feed` | Public community feed |
| `/my-coffees` | Authenticated journal |
| `/beans/:uuid` | Bean detail; legacy aliases resolve here |
| `/brews/:uuid` | Brew detail |
| `/add`, `/brew/new` | Add sheet and method selection |
| `/user/profile?tab=posts|saved` | Existing profile/saved navigation |
| `/login` | Frontend login screen |
| `/account/...` | Approved registration/recovery/settings screens |
| `/share/brew/:uuid` | Public, server-rendered share representation |

Support direct loading of form/detail routes as full screens and in-app sheet presentation where appropriate. Browser Back must close a sheet before leaving its underlying page; restore focus and scroll. Keep filters in validated URL parameters. Keep local UI state out of the server-data cache.

Query keys include resource identity, filters, and authenticated account identity for private data. Clear private caches on logout/account changes. Abort outstanding private requests or invalidate their session generation so late responses cannot repopulate a new user's cache.

Preserve `/node/{nid}`, `/my-coffees/{slug}`, and existing shared URLs through an access-aware resolver/redirect map. Do not infer a UUID from a numeric ID. Resolve alias collisions explicitly. Unknown API paths return JSON errors; they must never fall through to `index.html`.

## 8. Authentication and session contract

### Preferred transport

Use host-only Secure, HttpOnly Drupal session cookies on the frontend origin through external rewrites. Establish exact SameSite/path behavior during the spike. Keep credentials out of localStorage, URLs, and frontend environment variables.

The browser calls same-origin endpoints with credentials. Authenticated mutations carry a session-specific CSRF token. Reset the token after login/logout/session rotation. Do not automatically replay arbitrary writes on 401/403; distinguish expired authentication, CSRF failure, and denied access.

### Proposed session API

Paths are proposed contracts, not currently implemented routes:

| Method/path | Contract |
|---|---|
| `GET /api/v1/session` | Anonymous/authenticated state, minimal current user, permitted capabilities; private/no-store |
| `POST /api/v1/auth/login` | Approved email/username lookup, Drupal password verification/flood controls, rotated session |
| `POST /api/v1/auth/logout` | CSRF-protected session invalidation |
| `GET /session/token` | Drupal CSRF token for the current session |
| Account registration/recovery endpoints | Define against the approved auth plan; neutral recovery responses and supported Drupal mechanisms |

Do not blindly reuse core JSON login for email: the current module intentionally changes only the rendered login form and documents per-user flood-control implications. Preserve account IDs, names, provider links, and ownership. Do not implement custom password hashing, reset-token cryptography, or a second account database.

### Google during coexistence

Preserve Google if enabled by the effective rollout policy. OAuth start and callback must use a registered stable frontend-facing origin so Drupal creates the intended app session. Validate state, callback handling, session rotation, destination allowlisting, and account associations. A login performed only on the backend hostname does not automatically authenticate the frontend hostname.

Use a stable staging domain for OAuth acceptance. PR previews use a defined non-production login policy rather than dynamically registering unlimited Google callback URLs.

### Spike acceptance

On a deployed Vercel preview/staging build and real phones, prove:

1. Anonymous session bootstrap and successful approved login.
2. Correct Set-Cookie forwarding, cookie scope, and persistence.
3. Private list read and CSRF-protected write.
4. Logout, expired-session recovery, and account switching.
5. Google round trip if retained, and registration/recovery links.
6. Safe forwarded-host/protocol handling and accurate client IP for throttles through Acquia's proxy chain.
7. Correct production/staging redirects with no backend-host leakage.

Do not broadly trust arbitrary forwarded headers. Configure trusted ingress behavior with Acquia/Vercel's actual topology. If static rewrites cannot satisfy cookie/redirect requirements reliably, design one fixed-upstream gateway function, preserving authentication semantics and the function budget. A gateway is not an open proxy; restrict upstream paths/methods and avoid logging credentials. Re-test upload limits if introducing it.

## 9. Drupal API implementation

Keep JSON:API read-only initially. Use it where its entity representation is appropriate; add application-oriented JSON contracts for writes and aggregated screen reads. Centralize DTO mapping so components do not depend on Drupal field names or JSON:API relationship structure.

### Proposed resource contracts

| Method/path | Purpose and required checks |
|---|---|
| `GET/POST /api/v1/beans` | Owner-scoped journal and create; pagination, sort/filter allowlist |
| `GET/PATCH/DELETE /api/v1/beans/{uuid}` | Entity/field access, validated updates, delete relationship policy |
| `GET/POST /api/v1/brews` | Authorized list and create; validate referenced bean access |
| `GET/PATCH/DELETE /api/v1/brews/{uuid}` | Owner updates; visibility and relationship checks |
| `GET /api/v1/feed` | Public-only cards, safe author summaries, counts, stable cursor |
| `GET/PATCH /api/v1/me/profile` | Explicit private profile allowlist and updates |
| `GET /api/v1/me/stats` | Batched statistics; may be combined with profile bootstrap |
| `GET /api/v1/me/saved` | Paginated saved items with current access filtering |
| `PUT/DELETE /api/v1/brews/{uuid}/like` | Explicit desired state rather than retry-sensitive toggle |
| `PUT/DELETE /api/v1/content/{uuid}/bookmark` | Explicit desired state; restrict supported bundles |
| `POST /api/v1/brews/{uuid}/photo` | Multipart upload with entity/file checks |
| `PUT /api/v1/brews/{uuid}/visibility` | Explicit public/private state, caption validation, cache invalidation |

Reuse existing `/api/scrape-coffee`, `/api/places/search`, publish/photo, and engagement service logic. Maintain legacy endpoint compatibility while the Drupal UI is active; introduce versioned adapters rather than breaking old clients. Confirm supported bookmark/like entity types before finalizing paths.

### Data and validation

- Publish OpenAPI schemas and generate TypeScript types. Validate relevant responses at the API boundary and display unexpected-response errors safely.
- Define canonical units for weight, temperature, brew duration, ratings, and dates from existing field configuration. Return allowed values/defaults through a form metadata contract or generated schema.
- Preserve fields such as roaster, estate, roast, form factor, quantity/unit, purchase date, tasting notes, ratings, finished/reorder flags, brew method/ratio inputs, grind, and captions.
- Move form-only business validation/defaults into reusable module services or entity constraints. Explicitly run entity validation before saves; calling `save()` alone is not a substitute.
- Enforce create/update/delete entity access and writable-field allowlists. Never accept client-supplied owner IDs, roles, arbitrary file references, or unrestricted text formats.
- Use UUIDs in new contracts and centrally adapt legacy numeric IDs.
- Return plain text where rich text is unnecessary. Sanitize supported formatted content server-side.
- Define bean deletion behavior for related brews: reject with a useful conflict, detach, or another explicitly agreed policy. Do not silently cascade-delete user history.
- Reject stale edits with a version/revision precondition and return a 409/412 conflict response. Offer reload/copy-draft recovery rather than silently overwriting.
- For creates, use an account-scoped idempotency key backed by transactional uniqueness/deduplication. Do not enable blind mutation retries before this is in place.
- Persist authoritative results before reporting a successful save. A local draft is not a saved entity.

### Responses and pagination

Use consistent application response envelopes, for example `{data, meta}` and `{error: {code, message, fields}, request_id}`. Document success statuses and 400/401/403/404/409/412/413/422/429/5xx behavior. Preserve `Retry-After` where appropriate. Normalize retained legacy endpoint shapes in the frontend client.

Use stable cursor pagination (for example created time plus ID), bounded page sizes, batched author/count queries, and explicit sort/filter allowlists. Verify access through collections, direct reads, relationship inclusion, saved lists, and statistics. A public brew must not reveal a private referenced bean through an included relationship or derived label.

## 10. Uploads, image delivery, and sharing

The current upload controller accepts up to 5MB and stores brew photos in `public://brew-photos`. Private node access does not protect an independently reachable public file.

Implementation decisions:

- Decide and document whether private brew photos are private. Recommended: protected storage/access for private attachments and intentional public derivatives for public shares.
- Inventory existing files and define migration/revocation behavior before claiming existing private photos are protected. Making a node private cannot revoke downloaded copies.
- Test multipart requests through external rewrites with real camera photos and bounded sizes. External-rewrite behavior must be measured separately from Vercel Function limits.
- A function gateway has a documented 4.5MB payload limit, conflicting with the current 5MB allowance. If used, either impose a smaller whole-request limit after compression or design a separately authorized direct-upload flow. Do not assume streaming bypasses that limit.
- Resize/compress supported images before upload, with progress, cancellation, preview, and retry. Validate decoded type, size, dimensions, ownership, and file lifecycle on Drupal.
- Verify HEIC handling on the actual server/browser stack; an allowed extension does not establish conversion support. Provide a clear unsupported-format fallback.
- Produce thumbnail/card/detail image styles in Drupal. Return dimensions and responsive URLs; reserve layout space and lazy-load below-the-fold images.
- Keep paid image transformation out of Vercel initially. Direct-deliver public media from the backend/CDN when suitable; protected media uses an authenticated delivery path.

Use `/share/brew/{uuid}` for Drupal-rendered public HTML with Open Graph title/image/description and a link into the SPA. Do not serve the SPA fallback at this path. Verify visibility on every representation and disable shared caching initially so public-to-private transitions take effect at origin immediately. Third-party preview caches are outside immediate application control. If general public SEO becomes essential, reconsider SSR as a separate architecture decision.

## 11. Vercel routing, caching, and budget

### Route precedence

1. Static asset and manifest/service-worker paths.
2. Explicit approved API, token, auth/callback, and protected-media rewrites to Drupal.
3. Public share-page rewrite and known legacy-link resolution.
4. SPA navigation fallback for application routes.
5. Explicit unknown-resource/error behavior.

Avoid proxying all backend routes, exposing administration through the app origin, or sending API errors through the SPA fallback. Test query strings, methods, cookies, Location headers, status codes, and multipart bodies. Generate environment-specific routing deterministically; do not assume runtime substitution in static JSON configuration.

### Cache policy

| Resource | Policy |
|---|---|
| Hashed JS/CSS/public assets | Long-lived immutable caching |
| HTML shell/service worker | Revalidate/update promptly; retain prior assets for safe updates |
| Session, CSRF, private data, mutations | Explicit private/no-store; no shared CDN or service-worker response caching |
| Public feed/share APIs | No shared caching initially; add only with visibility-aware invalidation |
| Public image derivatives | Versioned URLs and explicit public policy |
| Private images | Authorized delivery; no public shared cache |

Vercel external rewrites can honor upstream caching headers. Drupal cache contexts alone do not tell a separate CDN how to partition private responses. Validate actual headers and responses through the deployed edge with two users and an anonymous request.

### Function budget enforcement

- Static Vite shell, rewrites, Drupal APIs, images, queues, and browser service worker: target 0 functions.
- Optional approved fixed-upstream gateway: budget 1, only after spike evidence.
- No unplanned per-screen API handlers, middleware functions, SSR adapters, or Vercel image handlers.
- Pin build dependencies/CLI versions and inspect actual `.vercel/output` function artifacts after `vercel build`.
- CI compares artifacts against an explicit allowlist/count. Zero is expected initially; any unexpected artifact fails. Independently fail at 12 or more functions.
- Verify the deployed Functions inventory as a release check; do not infer count from source filenames.

Function count is distinct from requests, bandwidth, origin transfer, image usage, and compute quotas. Monitor Vercel Hobby usage and Acquia/provider usage. Hobby eligibility is personal/non-commercial under current terms. The plan does not promise unlimited free operation. Recheck current limits before launch.

## 12. PWA, drafts, and mobile behavior

First release:

- Manifest with stable app identity, icons, theme colors, standalone display, and appropriate start URL/scope.
- Cache only the versioned application shell/public assets by default; provide an offline fallback.
- Store opt-in or clearly disclosed local drafts in IndexedDB, scoped by account and schema version. Handle storage denial, quota exhaustion, and eviction gracefully.
- Show local draft timestamps and explicit unsaved/saved-local/saved-server states. Never show a local draft as a successful server save.
- Clear private drafts/caches on logout according to the documented policy; warn about unsaved work before intentional logout. Account changes must never reveal another user's drafts.
- Prompt before applying a service-worker update when edits are unsaved. Use cache-version cleanup and a documented rollback/recovery path.
- Do not queue writes or depend on background sync in the first release. Offline saves preserve drafts and ask the user to retry when connected.

Mobile acceptance: touch targets of at least 44 CSS pixels where practical; no horizontal overflow at 320px; safe-area padding; usable keyboard-open forms; readable input text; focus trapping/restoration in dialogs; reduced motion; browser zoom preserved; VoiceOver/TalkBack labels and error announcements; scroll restoration and native back gestures preserved.

## 13. Performance and observability

Proposed budgets, measured against representative fixture data and the current app baseline:

| Measure | Target |
|---|---|
| Mobile LCP | At most 2.5s at p75 in field data once volume is sufficient |
| INP | At most 200ms at p75 |
| CLS | At most 0.1 |
| Initial app JavaScript | Aim for at most 180KB gzip; assess after vertical slice |
| Cached navigation | Useful content visible in approximately 100ms on reference device |
| Initial screen requests | Prefer one screen-data request after session dependency; no per-card waterfalls |
| Feed page | Bounded page size and responsive images; no full-history download |

Record device, network, build, dataset, and cold/warm state. Lab tests are proxies, not proof of field p75. Route-split forms and optional features, deduplicate queries, limit speculative prefetching, batch backend queries, and defer heavy image processing until needed.

Track frontend errors, API status/latency, authentication failures, upload failures, provider rate limits, asset failures, and Vercel usage. Correlate with safe request IDs. Exclude passwords, cookies, tokens, reset URLs, private notes, and uploaded content from logs/analytics. Establish a baseline before choosing additional monitoring dependencies.

## 14. Implementation phases and exit gates

Estimate: roughly 6–9 weeks for one experienced full-stack developer, subject to the auth/file-privacy findings and account-recovery scope. UI reuse reduces design effort but does not remove API, accessibility, and testing work.

| Phase | Deliverables | Exit gate | Indicative effort |
|---|---|---|---|
| 0: Baseline and decisions | Feature/route/field inventory, screenshots, current-version/deployment checks, auth-policy decision | Baseline and API scope agreed | 2–3 days |
| 1: Vertical slice | Vite preview, rewrites, login, private journal read/create, photo probe, logout | Real-phone flow works; function output verified | 3–5 days |
| 2: Backend contracts | Versioned APIs, reusable validation, privacy/relationship rules, OpenAPI/types | Contract/access tests pass | 5–8 days |
| 3: UI foundation | Ported CSS/tokens, shell/nav/drawer, form primitives, query/auth client | Baseline visual parity and keyboard behavior | 3–5 days |
| 4: Feature slices | Beans, brews, feed, engagement, profile, integrations, sharing | Functional parity matrix complete | 7–10 days |
| 5: PWA and optimization | Drafts, installation, images, performance/accessibility fixes | Device matrix and budgets reviewed | 3–5 days |
| 6: Pilot and cutover | Staging rehearsal, pilot, compatibility checks, rollback rehearsal | Release acceptance and production smoke checks | 2–4 days |

Phase 1 deliberately includes minimal UI before the full styling port to prove the highest-risk transport. Phase 2 APIs and Phase 4 screens should then be delivered as tested vertical slices, not separate big-bang backend/frontend rewrites.

## 15. Test plan

### Backend and contract tests

- Anonymous, owner A, unrelated user B, and administrator fixtures.
- Private/public collection, direct read, field/relationship inclusion, statistics, and saved-list access.
- Owner assignment, invalid referenced entities/files, blocked users, and field write allowlists.
- Login throttles, CSRF failures, session rotation/logout, approved registration/recovery behavior.
- Required fields, numeric bounds/units, malformed JSON, unsupported methods, stale revisions, duplicate submissions.
- Visibility changes, attachment access, bean deletion policy, and cache invalidation.
- Provider failures/rate limits and retained existing safe-fetching regressions.

### Frontend tests

- API normalization, session/token lifecycle, draft scoping, and desired-state rollback.
- Browser journeys: login → journal → create bean → create brew → upload → publish → bookmark → logout.
- Failed saves retain input; failed upload does not falsely publish a completed share.
- Filters, infinite loading, form autofill, deep links, history, reloads, expired sessions, and concurrent tabs.
- Two-account switching tests, including late network responses and persistent browser storage.
- Automated visual comparison against stable baseline fixtures, with reviewed intentional differences.
- Automated accessibility checks plus keyboard/VoiceOver/TalkBack verification.
- Physical iPhone Safari and Android Chrome; standalone PWA, rotation, keyboard, offline/reconnect, and update prompt.

### Deployment tests

- Actual Vercel function artifacts/inventory below 12 and matching the explicit allowlist.
- Production builds contain no provider secrets or backend-only configuration.
- API errors/unknown paths never return SPA HTML.
- Private data cannot be retrieved from another user's warmed CDN/service-worker cache.
- Backend-only releases do not inadvertently redeploy a mismatched frontend, and frontend-only builds do not alter Drupal artifacts.

## 16. Delivery, cutover, and rollback

1. Deploy additive Drupal APIs first; keep old routes/forms working.
2. Connect Vercel previews to dev/test only. Use controlled fixture data and captured email for automated tests.
3. Use a stable staging frontend/domain connected to Drupal test for OAuth, reset-link, upload, and PWA verification.
4. Pilot the new frontend with a small cohort while the old UI remains available. Both UIs use the same records; no dual-write database or account migration.
5. Compare account/content/relationship counts and representative ownership/visibility values before and after pilot writes.
6. Confirm app/public/backend domains, OAuth/reset destinations, legacy links, and media paths before switching production routing.
7. Release an identified frontend version compatible with the deployed API contract. Run production smoke checks and monitor errors, latency, and usage.
8. Keep the old theme/forms and backward-compatible APIs for an agreed rollback period. Remove unused frontend-serving paths only after sustained parity and a separate cleanup review.

Rollback triggers: failed login or protected writes, private-data exposure, lost/duplicated records, major mobile regression, or widespread asset/API failures.

Rollback procedure: restore the previous frontend deployment or route users back to the existing Drupal UI; keep additive APIs and data intact; restore previous routing/auth callback configuration as needed; handle service-worker update/cache recovery explicitly. Do not roll back the database to undo a UI release, because that would discard legitimate new writes. Sessions may require reauthentication when changing origins; account identity and records must remain intact.

## 17. Decisions to record before implementation

| Decision | Proposed default / action |
|---|---|
| Frontend location | `frontend/` in this repository with independent deployment |
| Public hostname | Retain current public hostname when practical; separate backend admin hostname |
| Auth transition | Preserve enabled providers; reconcile related plans before removing anything |
| Transport | External rewrites first; one gateway only if demonstrated necessary |
| Private photos | Protected access and explicit existing-file migration policy |
| Delete semantics | Explicitly agree related-brew behavior before bean deletion API |
| Public SEO | Dedicated share HTML; broader SSR deferred |
| Offline | Local drafts only; no background write queue |
| Visual baseline | Current intended UI, including navigation order and active auth layout |
| Function budget | 0 target, approved 1-function fallback, hard ceiling 11 |

## 18. Definition of done

- All agreed current workflows are available in the React frontend with reviewed styling parity.
- Existing accounts, ownership, content, interactions, and file references are preserved.
- Drupal enforces entity/field access and authoritative validation independently of the frontend.
- Real-device authentication, photos, keyboard behavior, navigation, PWA installation, and draft recovery pass.
- Build/deployment evidence verifies the function allowlist and fewer than 12 functions.
- Performance, accessibility, caching, visual, and API acceptance results are recorded.
- Staging/pilot and rollback rehearsal are complete, with documented remaining limitations.
- Documentation reflects the new frontend/backend boundaries and deployment workflow.

## 19. References

Documentation consulted during planning; verify deployment-sensitive limits again during implementation:

- [Vercel rewrites](https://vercel.com/docs/rewrites)
- [Vercel limits](https://vercel.com/docs/limits)
- [Vercel Function limits](https://vercel.com/docs/functions/limitations)
- [Vercel Hobby plan](https://vercel.com/docs/plans/hobby)
- [TanStack Router](https://tanstack.com/router/latest/docs/framework/react/guide/creating-a-router)
- [Astro design principles](https://docs.astro.build/en/concepts/why-astro/)
