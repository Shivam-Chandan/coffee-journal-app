# Production release

Status: release checks passed; deployment in progress.

## Destinations

- Drupal source: `Shivam-Chandan/coffee-journal-app`, branch `master` on GitHub.
- Drupal production: `https://eeschandan1.prod.acquia-sites.com`.
- Frontend Vercel project: `brewtal/coffee-journal` (Vercel requires lowercase project names).
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

## Verification still required during deployment

- Build, Dev, Test and Prod pipeline completion, including Acquia task/hook completion.
- Production API/cache headers, existing Drupal pages and optional-module status.
- Vercel route/function inventory and session cookies through external rewrites.
- Temporary native-account sign-in, protected save/reload and sign-out through Vercel.
- Removal of the temporary verification account and its owned content.
