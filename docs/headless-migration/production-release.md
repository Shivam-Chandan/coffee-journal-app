# Production release

Status: deployed and production smoke-tested on 2026-11-01.

## Destinations

- Drupal source: `Shivam-Chandan/coffee-journal-app`, branch `master` on GitHub.
- Drupal production: `https://eeschandan1.prod.acquia-sites.com`.
- Frontend Vercel project: `brewtal/coffee-journal` (Vercel requires lowercase project names).
- Frontend production URL: `https://coffee-journal-vert.vercel.app`.
- Vercel Git integration: GitHub `master`, root directory `frontend`, Node 22.
- Production frontend build variable: `DRUPAL_ORIGIN=https://eeschandan1.prod.acquia-sites.com`.

The frontend is a separate static deployment. Backend and frontend continue to
use Drupal accounts and records. Existing Drupal UI remains accessible.

## Release contents

Includes the headless frontend/APIs, bean privacy fix, provider/request security
improvements, CSRF fixes, regression checks, deployment artifact allowlisting,
and the optional native-auth module groundwork. The optional module remains
disabled unless explicitly selected in exported configuration.

The deployment hook now invokes Drush with a Bash array, enables only configured
custom modules, and stops on database/config/cache failures. It no longer stores
provider credentials in configuration or deletes shortcut entities during every
deployment. Production was checked to use a platform-provided hash salt rather
than the removed repository-root salt file.

Local-only `.claude/`, `.ddev/`, screenshots, generated build output, downloaded
Vercel environment files and dependencies are excluded from this release commit.

## Checks

- Frontend: 20 tests, TypeScript and production build pass; generated functions: 0.
- Drupal headless kernel tests: 7 tests / 108 assertions pass.
- Provider/access/request security suite: 67 tests / 288 assertions pass.
- Traditional-theme request tests: 6 pass.
- Artifact-inspection tests: 4 pass.
- Deployment hook shell syntax passes.
- Staged-source Gitleaks scan: passed with no leaks in the release tree.
- GitHub pipeline results are recorded after execution.

## Production verification

- [Drupal pipeline 41240568611](https://github.com/Shivam-Chandan/coffee-journal-app/actions/runs/41240568611)
  passed source/assembled-artifact scans, security regressions, Build, Dev, Test,
  and Prod promotion. Backend release commit: `b3370279b`.
- Production confirms API configuration is imported, the API update schema is
  `9001`, the proxy-compatible session configuration is active, and the optional
  native-auth module remains disabled.
- The deploy script is now at Acquia's standard
  `hooks/common/post-code-deploy/post-code-deploy.sh`. A pending legacy update
  hook was corrected to use Drupal 11's authenticated-role constant.
- API cookies omit Domain, allowing browsers to bind them to the Vercel origin.
  The live cookie is Secure, HttpOnly, SameSite=Lax, path `/`. Traditional UI
  cookie configuration is preserved. Three regression tests cover the change.
- A new temporary non-administrator production account signed in by username
  through Vercel, created a private coffee, reloaded its detail page, signed out,
  and could no longer access that private detail URL. Local drafts were cleared.
- The temporary account, its one owned verification entry, and its browser
  credential profile were removed after verification.
- The production frontend's Git-built deployment is Ready for `e5f1c5f94`.
  Generated/deployed function definitions remain empty: **0 functions**.
- Drupal and frontend login pages and the proxied anonymous session endpoint
  return HTTP 200. The tested private detail screen passed automated WCAG A/AA
  checks at a 390px viewport.

## Release checks and follow-up

- Security suite after cookie coverage: **70 tests / 304 assertions** pass.
- Headless kernel suite: **7 tests / 108 assertions** pass.
- Headless frontend GitHub checks (static build and API kernel test) passed.
- The separate legacy `CI` workflow still fails its repository-wide CodeSniffer
  and PHPStan jobs. Its compatibility rules include obsolete PHP 7.4 targets,
  alongside existing style/static-analysis findings. These were not disabled or
  suppressed; the green deployment/security and focused headless checks are
  distinct from that legacy workflow. General code-quality remediation remains
  follow-up work.
- The artifact scanner keeps its default detectors and narrowly permits exact
  upstream code patterns/constants in pinned CKEditor/Symfony files. Unused
  Drush examples are excluded from packaging. A fake injected key at the same
  CKEditor path was still rejected in a negative-control scan.
- Local-only development directories/screenshots and unrelated working-tree
  metadata remain uncommitted. No provider secret values or production database
  exports were added to GitHub or Vercel.
