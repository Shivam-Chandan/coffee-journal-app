# Coffee Journal frontend — pilot increment

React + TypeScript + Vite, TanStack Router/Query, and a refreshed Tailwind 3 design.
See the [migration plan](../docs/headless-migration-plan.md) and
[execution record](../docs/headless-migration/execution.md).

## Current functionality

- Email/username sign-in, password-reset request, and sign-out against Drupal sessions.
- Journal list, coffee detail, and coffee create/edit with URL autofill.
- Community feed, brew detail, and brew create/edit with the method picker.
- Add sheet, sharing controls, delete confirmations, likes, and saved items.
- Profile with Brews and Saved tabs plus counts.
- Loading, empty, signed-out, validation, conflict, and retry states.
- Account-scoped local drafts that never claim to be a server save.
- Static Vercel Build Output with a zero-function allowlist and hard ceiling of 11.

Still pending: photo upload, registration,
public share pages with Open Graph metadata, legacy URL resolution, and PWA
offline behavior. Use the existing Drupal UI for those workflows.

Authentication scope: username/email and password. Google login is not required
for the frontend migration or its acceptance tests. Existing Drupal Google
configuration is retained for the traditional UI.

## Local development

Requirements: Node 22.12+ and a running Drupal backend with the new read endpoints.
From the repository root, run `ddev start` and `ddev drush cr` if needed.

From this directory:

```sh
npm ci
npm run dev
```

Open <http://127.0.0.1:5173>. The default dev proxy targets
`https://brewtal.ddev.site`. To use another backend, set `DRUPAL_ORIGIN` in your
shell or a local `.env` file using `.env.example`. Use non-production backends for
development. Never put provider credentials or Drupal secrets in `VITE_*` variables.

The dev proxy forwards `/api` and `/session/token`. It strips upstream cookie
domains for local development and accepts DDEV's local certificate only for
`.ddev.site` hosts. These conveniences are not proof of production cookie behavior.
Signing in directly on the Drupal hostname does not sign in the localhost origin.
Use the isolated backend tests for owner access until the login transport spike is ready.

## Verification

```sh
npm run typecheck
npm test
DRUPAL_ORIGIN=https://eeschandan1dev.prod.acquia-sites.com npm run build:vercel
```

`build:vercel` compiles the app and writes `.vercel/output/config.json` plus static
assets using Vercel Build Output API v3. The checker inspects generated function
artifacts and rejects any unapproved function. It fails closed when build output
is absent, and rejects 12+ functions independently of the allowlist.

Backend tests, from the repository root:

```sh
ddev exec env SIMPLETEST_DB=sqlite://localhost/:memory: php vendor/bin/phpunit \
  --no-configuration --bootstrap docroot/core/tests/bootstrap.php \
  docroot/modules/custom/coffee_journal_api/tests/src/Kernel/HeadlessReadTest.php
```

These tests use temporary database fixtures, not the running site's users/content.

## Vercel project configuration

1. Set the project Root Directory to `frontend` and Node to 22.
2. Keep framework preset **Other** (the checked-in config uses `framework: null`).
3. Use the checked-in `npm run build:vercel` build command. Leave the dashboard
   Output Directory override unset: this is Build Output API output, not a `dist`
   directory deployment.
4. Set **server-side build environment** `DRUPAL_ORIGIN` separately for Preview
   and Production. It must be an HTTPS origin with no credentials/path/query.
   Preview should target Drupal dev/test; production should target Drupal prod.
5. Verify external rewrites, cookies, API headers, and the deployed Functions
   inventory in a preview before routing production traffic here.

Production is deployed at <https://coffee-journal-vert.vercel.app> in
`brewtal/coffee-journal`. GitHub `master` builds `frontend/` with Node 22;
production proxies to Drupal prod and previews proxy to Drupal dev. See the
[production release record](../docs/headless-migration/production-release.md).
`.vercel` is generated/ignored and must not be committed. If using Vercel CLI `--prebuilt`,
link/pull project configuration first and rebuild with the correct environment.
The build output check does not replace the deployed-inventory acceptance check.

API routes precede the SPA fallback and explicitly disable shared caching. Static
hashed assets are immutable; HTML revalidates. Do not add auth or admin proxy routes
until their scope, callbacks, cookie handling, and CSRF behavior are tested.

The Acquia push pipeline ignores frontend-only changes. Changes to Drupal API
files still trigger the backend pipeline. Existing pull-request security checks
remain in place, and the frontend workflow runs its own static/API checks.

## Read API contracts (pilot)

All three endpoints return JSON with `Cache-Control: private, no-store`, plus
CDN no-store headers. Existing APIs and read-only JSON:API remain intact.

| GET endpoint | Response |
|---|---|
| `/api/v1/session` | `{data: {authenticated: false, user: null}}` or authenticated `{id, name}` |
| `/api/v1/beans` | `{data: [{id, nid, title, brand, roast, rating}], meta: {next}}`; anonymous requests receive 401 |
| `/api/v1/feed` | `{data: [{id, nid, title, method, ratio, caption}], meta: {next}}` |

Collections contain at most 20 published accessible nodes, sorted by descending
numeric node ID. Pass `?before=<next>` for older IDs; `next: null` ends pagination.
Invalid cursors receive 400. Bean lists additionally require current-user ownership;
feed lists explicitly require public visibility, including for authenticated owners.
The controller checks field access and does not dereference private beans, files,
or user accounts. Full OpenAPI/generated types and richer card data are later tasks;
the current frontend validates these pilot responses with Zod.

## Frontend design

The user approved a visual refresh after the initial styling port. Keep existing
screen structure, routes, and navigation order, with the new frontend visual system:

- Locally hosted variable DM Sans (no Google Fonts request).
- Small, shared inline SVG icon set in `src/components/Icon.tsx`; no emoji icons.
- Charcoal/sage dark mode and off-white/forest light mode.
- Readable card typography, roast-themed decorative art, compact rating summaries.
- Rounded, safe-area-aware bottom dock with the same five navigation destinations.
- Visible focus states, 44px+ controls, reduced-motion support, and contrast-tested text.

`src/styles/app.css` owns the frontend tokens and components. The Drupal theme is
the workflow/structure reference, no longer the pixel-parity target. Existing data
availability and migration limitations still apply; visual fixtures are not live data.
