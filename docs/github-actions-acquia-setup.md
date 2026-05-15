# GitHub Actions → Acquia Cloud: CI/CD Setup

**Project:** Brewtal / Coffee Journal (`eeschandan1`)
**Repository:** https://github.com/Shivam-Chandan/coffee-journal-app
**Acquia App UUID:** `04f18e5e-3e19-4698-ac58-c162df025345`

---

## Overview

This document describes how GitHub Actions was connected to Acquia Cloud Platform to replace Acquia Pipelines as the CI/CD system. It covers:

- The architecture and how the two systems connect
- Every workflow file created, what it does, and its full template
- The one-time setup steps (SSH keys, secrets, environments)
- How to use the pipeline day-to-day
- Known gotchas and fixes discovered during implementation

---

## Architecture

GitHub Actions does not have native Acquia Cloud integration. The connection is made through two mechanisms:

### 1. SSH key — for pushing code

Acquia Cloud hosts a private git remote for every application. GitHub Actions authenticates to that remote using an RSA SSH key pair:

```
GitHub Actions runner
  └── loads private key from secret ACQUIA_SSH_PRIVATE_KEY
        └── SSH connects to Acquia git remote
              └── Acquia verifies against registered public key
                    └── git push succeeds → code lands on Acquia
```

### 2. Acquia CLI — for switching environments

After pushing code to Acquia git, the Acquia Cloud API is called via `acli` (Acquia CLI) to tell a specific environment (dev / test / prod) to switch to the newly pushed branch. This triggers Acquia's `post-code-deploy` hook automatically on the server.

```
GitHub Actions runner
  └── authenticates to Acquia Cloud API with ACQUIA_CLI_KEY + ACQUIA_CLI_SECRET
        └── calls: acli api:environments:code-switch <env-id> <branch>
              └── Acquia switches environment
                    └── post-code-deploy hook runs: config:import, updatedb, cache:rebuild
```

> **Important:** `acli api:environments:code-switch` takes the branch as a **positional argument**, not a `--branch=` flag. Using `--branch=` causes the command to fail with "The --branch option does not exist."
>
> ```bash
> # Correct
> acli api:environments:code-switch "${ENV_ID}" "${DEPLOY_BRANCH}" --no-interaction
>
> # Wrong — will fail
> acli api:environments:code-switch "${ENV_ID}" --branch="${DEPLOY_BRANCH}"
> ```

### Deploy branch naming convention

To stay compatible with the existing Acquia Pipelines branch history, the deploy branches follow Acquia's own naming convention:

| Source branch | Acquia deploy branch |
|---|---|
| `master` | `pipelines-build-master` |
| `main` | `pipelines-build-main` |

> **Important:** The prefix is `pipelines-build-` (plural). Using `pipeline-build-` (without the `s`) creates a different branch that won't match what Acquia Pipelines previously used, and the `post-code-deploy` hook may not fire as expected.

---

## Full Pipeline Flow

```
Push to master
      │
      ▼
┌─────────────────────────────┐
│  Stage 1: CI                │  Runs on every push AND pull request
│  - composer validate        │
│  - composer audit           │
│  - PHP CodeSniffer (phpcs)  │
│  - PHPStan                  │
└──────────────┬──────────────┘
               │ pass
               ▼
┌─────────────────────────────┐
│  Stage 2: Build             │  Runs on push only (not PRs)
│  - composer install --no-dev│
│  - Tailwind CSS compile     │
│  - restore scaffold files   │  ← .htaccess, index.php (see gotchas)
│  - artifact prep & verify   │
│  - upload GitHub artifact   │  ← with include-hidden-files: true
└──────────────┬──────────────┘
               │
               ▼
┌─────────────────────────────┐
│  Stage 3a: Deploy Dev       │  Automatic — no approval needed
│  - remove all .gitignore    │  ← so vendor/ and .htaccess are committed
│  - push artifact → Acquia   │
│  - switch dev env to branch │
└──────────────┬──────────────┘
               │
               ▼
┌─────────────────────────────┐
│  Stage 3b: Deploy Test      │  Requires manual approval
│  - push artifact → Acquia   │  (GitHub Environment: acquia-test)
│  - switch test env          │
└──────────────┬──────────────┘
               │
               ▼
┌─────────────────────────────┐
│  Stage 3c: Deploy Prod      │  Requires manual approval
│  - push artifact → Acquia   │  (GitHub Environment: acquia-prod)
│  - switch prod env          │
└─────────────────────────────┘
```

On **pull requests**: only Stage 1 (CI) runs. No build or deploy is triggered.

---

## Files Created

Five workflow files were created under `.github/workflows/`:

| File | Type | Purpose |
|---|---|---|
| `ci.yml` | Standalone + reusable | Code quality: phpcs, phpstan, composer validate |
| `build.yml` | Reusable (`workflow_call`) | Build deployable artifact |
| `deploy.yml` | Reusable (`workflow_call`) | Deploy artifact to one Acquia environment |
| `pipeline.yml` | Orchestrator | Chains CI → Build → Dev → Test → Prod |
| `test-connection.yml` | Manual (`workflow_dispatch`) | Verifies all secrets and SSH connection work |

---

## Workflow File Templates

### 1. `ci.yml` — Code Quality Checks

Runs on every push (except `pipelines-build-**` and `dependabot/**`) and on every pull request to `master` / `main`.

**Jobs:**
- `validate` — `composer validate --strict` then `composer audit`
- `phpcs` — PHP CodeSniffer using `AcquiaDrupalStrict` standard, scoped to `docroot/modules/custom` and `docroot/themes/custom`
- `phpstan` — PHPStan static analysis at level 1, same scope
- `all-ci-passed` — gate job for branch protection rules

```yaml
---
name: CI

on:
  push:
    branches-ignore:
      - 'pipelines-build-**'
      - 'dependabot/**'
  pull_request:
    branches:
      - master
      - main

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  validate:
    name: Composer Validate & Audit
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
          coverage: none
      - run: composer validate --strict --no-interaction
      - run: composer install --prefer-dist --no-interaction --no-progress
        env:
          COMPOSER_NO_INTERACTION: '1'
          COMPOSER_MEMORY_LIMIT: '-1'
      - run: composer audit --no-interaction
        continue-on-error: true

  phpcs:
    name: PHP CodeSniffer
    runs-on: ubuntu-24.04
    needs: validate
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
          coverage: none
      - uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
      - run: composer install --prefer-dist --no-interaction --no-progress
        env:
          COMPOSER_NO_INTERACTION: '1'
          COMPOSER_MEMORY_LIMIT: '-1'
      - run: |
          vendor/bin/phpcs \
            --standard=phpcs.xml.dist \
            --report=full \
            --colors -p \
            docroot/modules/custom \
            docroot/themes/custom

  phpstan:
    name: PHPStan
    runs-on: ubuntu-24.04
    needs: validate
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
          coverage: none
      - uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
      - run: composer install --prefer-dist --no-interaction --no-progress
        env:
          COMPOSER_NO_INTERACTION: '1'
          COMPOSER_MEMORY_LIMIT: '-1'
      - run: |
          vendor/bin/phpstan analyse \
            --configuration=phpstan.neon.dist \
            --no-progress \
            --error-format=table

  all-ci-passed:
    name: All CI checks passed
    if: always()
    runs-on: ubuntu-latest
    needs: [validate, phpcs, phpstan]
    steps:
      - uses: re-actors/alls-green@release/v1
        with:
          jobs: ${{ toJSON(needs) }}
```

---

### 2. `build.yml` — Reusable Artifact Builder

A `workflow_call` reusable workflow. Called by `pipeline.yml`. Mirrors the existing `acquia-pipelines.yml` build steps exactly.

**Steps:**
1. `composer install --no-dev --optimize-autoloader --no-scripts` — production dependencies only. `--no-scripts` skips Composer scaffold (scaffold files are restored manually in the next step)
2. Restores Drupal core scaffold files excluded by `docroot/.gitignore` — see gotcha below
3. `npm ci` + `npx tailwindcss --minify` — compiles Tailwind CSS for the `coffee_journal` theme
4. Strips dev-only files (`phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`)
5. Removes `node_modules` from the artifact
6. Verifies critical files exist before uploading
7. Uploads the entire workspace as a named GitHub Actions artifact (`acquia-artifact-<run_id>`) with `include-hidden-files: true`

**Outputs:** `artifact-name` — passed to the deploy workflow.

> **Gotcha — Drupal scaffold files missing from artifact:**
>
> `docroot/.gitignore` (managed by `composer/drupal-scaffold` with `gitignore: true`) explicitly excludes `.htaccess`, `index.php`, `robots.txt`, and `autoload.php` from git tracking. These files are essential for Drupal to run — without `.htaccess` the web server cannot route requests to Drupal's `index.php` and every URL except `/` returns a 404.
>
> Since `composer install --no-scripts` skips scaffold, these files must be restored manually after the composer step:
>
> ```bash
> SCAFFOLD_SRC="docroot/core/assets/scaffold/files"
> cp "${SCAFFOLD_SRC}/htaccess"   docroot/.htaccess
> cp "${SCAFFOLD_SRC}/index.php"  docroot/index.php
> cp "${SCAFFOLD_SRC}/robots.txt" docroot/robots.txt
> # Write the correct Drupal autoload.php shim — do NOT copy vendor/autoload.php
> printf '<?php\nreturn require __DIR__ . '"'"'/../vendor/autoload.php'"'"';\n' > docroot/autoload.php
> ```
>
> Note: `docroot/autoload.php` must be a shim pointing to `../vendor/autoload.php`, **not** a copy of `vendor/autoload.php` itself. Copying `vendor/autoload.php` directly causes a fatal error (`Failed opening required .../composer/autoload_real.php`) because Composer's autoloader uses relative paths that only work from the `vendor/` directory.

> **Gotcha — `actions/upload-artifact` drops dotfiles by default:**
>
> `actions/upload-artifact@v4` does not include dotfiles (files starting with `.`) unless explicitly told to. Since `docroot/.htaccess` is a dotfile, it will be silently dropped from the artifact zip unless you add `include-hidden-files: true`:
>
> ```yaml
> - uses: actions/upload-artifact@v4
>   with:
>     name: acquia-artifact-${{ github.run_id }}
>     path: |
>       .
>       !.git
>     include-hidden-files: true   # ← required for .htaccess
>     retention-days: 7
>     if-no-files-found: error
> ```

```yaml
---
name: Build

on:
  workflow_call:
    inputs:
      ref:
        description: 'Git ref to build'
        required: false
        type: string
        default: ''
    outputs:
      artifact-name:
        description: 'Name of the uploaded GitHub Actions artifact'
        value: ${{ jobs.build.outputs.artifact-name }}

jobs:
  build:
    name: Build Artifact
    runs-on: ubuntu-24.04
    outputs:
      artifact-name: ${{ steps.set-artifact-name.outputs.name }}
    steps:
      - uses: actions/checkout@v4
        with:
          ref: ${{ inputs.ref }}
          fetch-depth: 0

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
          coverage: none
          extensions: gd, pdo_mysql, mbstring, xml, curl, zip, opcache

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: 'docroot/themes/custom/coffee_journal/package-lock.json'

      - name: Composer install (production)
        run: |
          composer install \
            --no-dev --optimize-autoloader \
            --no-interaction --prefer-dist \
            --no-scripts --no-progress
        env:
          COMPOSER_NO_INTERACTION: '1'
          COMPOSER_MEMORY_LIMIT: '-1'

      # Restore Drupal core scaffold files that are excluded by docroot/.gitignore.
      # These files are critical for Drupal to run on Acquia Cloud.
      # --no-scripts above skips scaffold, so we restore them manually here.
      - name: Restore Drupal core docroot files
        run: |
          SCAFFOLD_SRC="docroot/core/assets/scaffold/files"
          cp "${SCAFFOLD_SRC}/htaccess"   docroot/.htaccess
          cp "${SCAFFOLD_SRC}/index.php"  docroot/index.php
          cp "${SCAFFOLD_SRC}/robots.txt" docroot/robots.txt
          # Write correct autoload.php shim — NOT a copy of vendor/autoload.php
          printf '<?php\nreturn require __DIR__ . '"'"'/../vendor/autoload.php'"'"';\n' \
            > docroot/autoload.php
          ls -la docroot/.htaccess docroot/index.php

      - name: Build Tailwind CSS
        working-directory: docroot/themes/custom/coffee_journal
        run: |
          npm ci --prefer-offline --no-fund --no-audit
          NODE_ENV=production npx tailwindcss \
            -i ./src/css/app.css \
            -o ./css/coffee-theme.css \
            --minify

      - name: Prepare artifact
        run: |
          rm -f phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist .travis.yml .env.example
          rm -rf docroot/themes/custom/coffee_journal/node_modules
          mkdir -p docroot/sites/default/files docroot/sites/default/private
          test -f docroot/.htaccess            || (echo "ERROR: .htaccess missing" && exit 1)
          test -f docroot/index.php            || (echo "ERROR: index.php missing" && exit 1)
          test -f config/default/core.extension.yml
          test -f docroot/modules/custom/coffee_journal_api/coffee_journal_api.info.yml
          test -f docroot/themes/custom/coffee_journal/css/coffee-theme.css
          test -f docroot/themes/custom/coffee_journal/js/app.js

      - name: Set artifact name
        id: set-artifact-name
        run: echo "name=acquia-artifact-${{ github.run_id }}" >> "$GITHUB_OUTPUT"

      - uses: actions/upload-artifact@v4
        with:
          name: acquia-artifact-${{ github.run_id }}
          path: |
            .
            !.git
          include-hidden-files: true   # Required — preserves .htaccess and other dotfiles
          retention-days: 7
          if-no-files-found: error
```

---

### 3. `deploy.yml` — Reusable Acquia Deployer

A `workflow_call` reusable workflow. Called three times by `pipeline.yml` — once per environment. Uses the `environment:` block to map to a GitHub Environment, enabling approval gates for test and prod.

**Steps:**
1. Loads SSH private key via `webfactory/ssh-agent` (supports passphrase)
2. Adds Acquia's git server to `known_hosts`
3. Downloads the artifact from GitHub artifact storage
4. Removes all `.gitignore` files from the artifact directory — see gotcha below
5. Pushes the artifact as a new commit to Acquia git on the deploy branch (`pipelines-build-<source>`)
6. Installs `acli` (Acquia CLI)
7. Authenticates to Acquia Cloud API
8. Calls `acli api:environments:code-switch <env-id> <branch>` to switch the target environment — this fires the existing `post-code-deploy` hook on the server
9. Writes a deployment summary to the GitHub Actions job summary

**Inputs:** `environment`, `artifact-name`, `source-branch`

**Secrets consumed:** `ACQUIA_CLI_KEY`, `ACQUIA_CLI_SECRET`, `ACQUIA_GIT_URL`, `ACQUIA_SSH_PRIVATE_KEY`, `ACQUIA_SSH_PASSPHRASE`

> **Gotcha — `.gitignore` files prevent `vendor/` and `.htaccess` from reaching Acquia:**
>
> The source repository's `.gitignore` files correctly exclude `vendor/`, `docroot/.htaccess` etc. from source control. However, the deploy step initialises a **new** git repo in the artifact directory and commits everything for pushing to Acquia. If any `.gitignore` files are present in the artifact, git will still respect them and silently exclude the same files — meaning `vendor/` (which Drupal needs to bootstrap) never reaches the server.
>
> The fix is to delete all `.gitignore` files from the artifact directory before `git add`:
>
> ```bash
> find . -name ".gitignore" -not -path "./.git/*" -delete
> git add -A
> ```

> **Gotcha — `acli api:environments:code-switch` syntax:**
>
> The branch must be passed as a **positional argument**, not a `--branch` flag:
>
> ```bash
> # Correct
> acli api:environments:code-switch "${ENV_ID}" "${DEPLOY_BRANCH}" --no-interaction
>
> # Wrong — fails with "The --branch option does not exist"
> acli api:environments:code-switch "${ENV_ID}" --branch="${DEPLOY_BRANCH}"
> ```

> **Note on `ACQUIA_DEV_ENV_UUID` secret:**
>
> To avoid an API call to look up the dev environment ID on every deploy, you can add an optional `ACQUIA_DEV_ENV_UUID` repository secret containing the dev environment's compound ID (e.g. `146125-04f18e5e-3e19-4698-ac58-c162df025345`). The deploy workflow uses this as a shortcut when deploying to dev. It is optional — the workflow falls back to an API lookup if not set.

```yaml
---
name: Deploy

on:
  workflow_call:
    inputs:
      environment:
        required: true
        type: string
      artifact-name:
        required: true
        type: string
      source-branch:
        required: false
        type: string
        default: 'master'
    secrets:
      ACQUIA_CLI_KEY:
        required: true
      ACQUIA_CLI_SECRET:
        required: true
      ACQUIA_GIT_URL:
        required: true
      ACQUIA_SSH_PRIVATE_KEY:
        required: true
      ACQUIA_APP_UUID:
        required: true
      ACQUIA_DEV_ENV_UUID:
        required: false
      ACQUIA_SSH_PASSPHRASE:
        required: false

jobs:
  deploy:
    name: Deploy to ${{ inputs.environment }}
    runs-on: ubuntu-24.04
    environment:
      name: acquia-${{ inputs.environment }}
      url: >-
        ${{
          inputs.environment == 'prod'
          && 'https://eeschandan1.prod.acquia-sites.com'
          || format('https://eeschandan1{0}.prod.acquia-sites.com', inputs.environment)
        }}
    steps:
      - uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.ACQUIA_SSH_PRIVATE_KEY }}

      - name: Trust Acquia git host
        run: |
          mkdir -p ~/.ssh
          ssh-keyscan -H svn-15816.prod.hosting.acquia.com >> ~/.ssh/known_hosts
          chmod 600 ~/.ssh/known_hosts

      - uses: actions/download-artifact@v4
        with:
          name: ${{ inputs.artifact-name }}
          path: artifact

      - name: Compute deploy branch
        id: branch
        run: |
          # Must use the 'pipelines-build-' prefix (with the 's') to match Acquia conventions
          echo "deploy_branch=pipelines-build-${{ inputs.source-branch }}" >> "$GITHUB_OUTPUT"

      - name: Configure git identity
        run: |
          git config --global user.email "github-actions[bot]@users.noreply.github.com"
          git config --global user.name "github-actions[bot]"

      - name: Push artifact to Acquia git
        working-directory: artifact
        run: |
          DEPLOY_BRANCH="${{ steps.branch.outputs.deploy_branch }}"

          git init
          git remote add acquia "${{ secrets.ACQUIA_GIT_URL }}"
          git fetch acquia "${DEPLOY_BRANCH}" 2>/dev/null \
            && git checkout -b "${DEPLOY_BRANCH}" "acquia/${DEPLOY_BRANCH}" \
            || git checkout -b "${DEPLOY_BRANCH}"

          # Remove all .gitignore files so vendor/, .htaccess etc. are all committed.
          # The source repo's .gitignore files correctly exclude these from source control,
          # but this is a deploy artifact — we need everything on Acquia.
          find . -name ".gitignore" -not -path "./.git/*" -delete

          git add -A
          if ! git diff --cached --quiet; then
            git commit -m "deploy: GitHub Actions run ${{ github.run_id }} @ ${{ github.sha }}"
          fi
          git push acquia "${DEPLOY_BRANCH}:${DEPLOY_BRANCH}" --force-with-lease \
            || git push acquia "${DEPLOY_BRANCH}:${DEPLOY_BRANCH}" --force

      - name: Install Acquia CLI
        run: |
          curl -sSL https://github.com/acquia/cli/releases/latest/download/acli.phar \
            -o /usr/local/bin/acli
          chmod +x /usr/local/bin/acli

      - name: Authenticate with Acquia Cloud API
        run: |
          acli auth:login \
            --key="${{ secrets.ACQUIA_CLI_KEY }}" \
            --secret="${{ secrets.ACQUIA_CLI_SECRET }}" \
            --no-interaction

      - name: Switch environment to deploy branch
        run: |
          APP_UUID="${{ secrets.ACQUIA_APP_UUID }}"
          ENV="${{ inputs.environment }}"
          DEPLOY_BRANCH="${{ steps.branch.outputs.deploy_branch }}"

          # Use cached dev env UUID if available to avoid an API call
          DEV_ENV_UUID="${{ secrets.ACQUIA_DEV_ENV_UUID }}"
          if [[ "${ENV}" == 'dev' && -n "${DEV_ENV_UUID}" ]]; then
            ENV_ID="${DEV_ENV_UUID}"
          else
            ENV_ID=$(acli api:environments:list "${APP_UUID}" --no-interaction \
              | python3 -c "
          import json,sys
          for e in json.load(sys.stdin):
              if e.get('name')=='${ENV}': print(e['id']); break
          ")
          fi

          if [[ -z "${ENV_ID}" ]]; then
            echo "ERROR: Could not find environment ID for '${ENV}'"
            exit 1
          fi

          # Branch is a positional argument — NOT --branch= flag
          acli api:environments:code-switch "${ENV_ID}" "${DEPLOY_BRANCH}" --no-interaction
```

---

### 4. `pipeline.yml` — Main Orchestrator

The entry point. Triggered on push to `master`/`main`, on pull requests, and manually via `workflow_dispatch`. Calls the three reusable workflows in sequence.

**Key behaviours:**
- Pull requests → CI only, no build or deploy
- Push to `master`/`main` → CI → Build → auto-deploy dev → approval-gated test → approval-gated prod
- `workflow_dispatch` → choose any single target environment and source branch
- Concurrency lock: one pipeline per branch at a time; in-progress runs cancelled on new push (except prod)
- Changes to `**.md`, `scripts/**`, `tests/**` do not trigger a build/deploy

```yaml
---
name: Pipeline

on:
  push:
    branches: [master, main]
    paths-ignore:
      - '**.md'
      - '.github/PULL_REQUEST_TEMPLATE.md'
      - 'scripts/**'
      - 'tests/**'
  pull_request:
    branches: [master, main]
  workflow_dispatch:
    inputs:
      target_env:
        required: true
        default: 'dev'
        type: choice
        options: [dev, test, prod]
      source_branch:
        required: false
        default: 'master'
        type: string

concurrency:
  group: pipeline-${{ github.ref }}
  cancel-in-progress: ${{ !contains(github.ref, 'prod') }}

jobs:
  build:
    if: github.event_name == 'push' || github.event_name == 'workflow_dispatch'
    uses: ./.github/workflows/build.yml
    with:
      ref: ${{ github.sha }}
    secrets: inherit

  deploy-dev:
    needs: build
    if: >-
      github.event_name == 'push' ||
      (github.event_name == 'workflow_dispatch' &&
       (github.event.inputs.target_env || 'dev') == 'dev')
    uses: ./.github/workflows/deploy.yml
    with:
      environment: dev
      artifact-name: ${{ needs.build.outputs.artifact-name }}
      source-branch: ${{ github.event.inputs.source_branch || github.ref_name }}
    secrets:
      ACQUIA_CLI_KEY: ${{ secrets.ACQUIA_CLI_KEY }}
      ACQUIA_CLI_SECRET: ${{ secrets.ACQUIA_CLI_SECRET }}
      ACQUIA_GIT_URL: ${{ secrets.ACQUIA_GIT_URL }}
      ACQUIA_SSH_PRIVATE_KEY: ${{ secrets.ACQUIA_SSH_PRIVATE_KEY }}
      ACQUIA_APP_UUID: ${{ secrets.ACQUIA_APP_UUID }}
      ACQUIA_DEV_ENV_UUID: ${{ secrets.ACQUIA_DEV_ENV_UUID }}
      ACQUIA_SSH_PASSPHRASE: ${{ secrets.ACQUIA_SSH_PASSPHRASE }}

  deploy-test:
    needs: [build, deploy-dev]
    if: >-
      (github.event_name == 'push' &&
       (github.ref == 'refs/heads/master' || github.ref == 'refs/heads/main')) ||
      (github.event_name == 'workflow_dispatch' &&
       (github.event.inputs.target_env == 'test' || github.event.inputs.target_env == 'prod'))
    uses: ./.github/workflows/deploy.yml
    with:
      environment: test
      artifact-name: ${{ needs.build.outputs.artifact-name }}
      source-branch: ${{ github.event.inputs.source_branch || github.ref_name }}
    secrets:
      ACQUIA_CLI_KEY: ${{ secrets.ACQUIA_CLI_KEY }}
      ACQUIA_CLI_SECRET: ${{ secrets.ACQUIA_CLI_SECRET }}
      ACQUIA_GIT_URL: ${{ secrets.ACQUIA_GIT_URL }}
      ACQUIA_SSH_PRIVATE_KEY: ${{ secrets.ACQUIA_SSH_PRIVATE_KEY }}
      ACQUIA_APP_UUID: ${{ secrets.ACQUIA_APP_UUID }}
      ACQUIA_DEV_ENV_UUID: ${{ secrets.ACQUIA_DEV_ENV_UUID }}
      ACQUIA_SSH_PASSPHRASE: ${{ secrets.ACQUIA_SSH_PASSPHRASE }}

  deploy-prod:
    needs: [build, deploy-test]
    if: >-
      (github.event_name == 'push' &&
       (github.ref == 'refs/heads/master' || github.ref == 'refs/heads/main')) ||
      (github.event_name == 'workflow_dispatch' &&
       github.event.inputs.target_env == 'prod')
    uses: ./.github/workflows/deploy.yml
    with:
      environment: prod
      artifact-name: ${{ needs.build.outputs.artifact-name }}
      source-branch: ${{ github.event.inputs.source_branch || github.ref_name }}
    secrets:
      ACQUIA_CLI_KEY: ${{ secrets.ACQUIA_CLI_KEY }}
      ACQUIA_CLI_SECRET: ${{ secrets.ACQUIA_CLI_SECRET }}
      ACQUIA_GIT_URL: ${{ secrets.ACQUIA_GIT_URL }}
      ACQUIA_SSH_PRIVATE_KEY: ${{ secrets.ACQUIA_SSH_PRIVATE_KEY }}
      ACQUIA_APP_UUID: ${{ secrets.ACQUIA_APP_UUID }}
      ACQUIA_DEV_ENV_UUID: ${{ secrets.ACQUIA_DEV_ENV_UUID }}
      ACQUIA_SSH_PASSPHRASE: ${{ secrets.ACQUIA_SSH_PASSPHRASE }}
```

---

### 5. `test-connection.yml` — Connection Verification (manual only)

A read-only diagnostic workflow. Triggered manually from the Actions tab. Used to verify all secrets and SSH connectivity are working before running a real deploy.

**Checks:**
1. SSH key loads without error (validates `ACQUIA_SSH_PRIVATE_KEY` + `ACQUIA_SSH_PASSPHRASE`)
2. Prints the loaded public key so it can be compared against Acquia Cloud UI
3. `git ls-remote` against `ACQUIA_GIT_URL` (validates SSH auth to Acquia git)
4. `acli auth:login` (validates `ACQUIA_CLI_KEY` + `ACQUIA_CLI_SECRET`)
5. `acli api:applications:find` returns the Brewtal application (validates API permissions)

```yaml
---
name: Connection Test

on:
  workflow_dispatch:

jobs:
  test-connection:
    name: Test Acquia Secrets
    runs-on: ubuntu-24.04
    steps:
      - uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.ACQUIA_SSH_PRIVATE_KEY }}
          ssh-passphrase: ${{ secrets.ACQUIA_SSH_PASSPHRASE }}

      - name: Trust Acquia git host
        run: |
          mkdir -p ~/.ssh
          ssh-keyscan -H svn-15816.prod.hosting.acquia.com >> ~/.ssh/known_hosts
          chmod 600 ~/.ssh/known_hosts

      - name: Show loaded public key
        run: ssh-add -L

      - name: Test git connection
        run: git ls-remote "${{ secrets.ACQUIA_GIT_URL }}" HEAD

      - name: Install Acquia CLI
        run: |
          curl -sSL https://github.com/acquia/cli/releases/latest/download/acli.phar \
            -o /usr/local/bin/acli
          chmod +x /usr/local/bin/acli

      - name: Test API auth
        run: |
          acli auth:login \
            --key="${{ secrets.ACQUIA_CLI_KEY }}" \
            --secret="${{ secrets.ACQUIA_CLI_SECRET }}" \
            --no-interaction

      - name: Verify application accessible
        run: |
          acli api:applications:find ${{ secrets.ACQUIA_APP_UUID }} \
            --no-interaction \
            | python3 -c "
          import json,sys
          app=json.load(sys.stdin)
          print(f'App: {app[\"name\"]} | Status: {app[\"status\"]}')
          "
```

---

## One-Time Setup Process

### Step 1 — Generate an RSA SSH key pair

Run locally (no passphrase is simplest for CI, but a passphrase is supported):

```bash
ssh-keygen -t rsa -b 4096 -C "github-actions-deploy" -f ~/.ssh/acquia_github_actions
```

This produces two files:
- `~/.ssh/acquia_github_actions` — **private key** (goes to GitHub)
- `~/.ssh/acquia_github_actions.pub` — **public key** (goes to Acquia Cloud)

### Step 2 — Register the public key in Acquia Cloud

1. Go to **cloud.acquia.com → your profile avatar (top right) → SSH Keys**
2. Click **Add SSH key**
3. Paste the contents of `~/.ssh/acquia_github_actions.pub`
4. Give it a label like `github-actions-deploy`
5. Save — Acquia may take a few minutes to propagate the key

### Step 3 — Add GitHub repository secrets

Go to **GitHub repo → Settings → Secrets and variables → Actions → New repository secret** and add:

| Secret name | Value |
|---|---|
| `ACQUIA_CLI_KEY` | Acquia Cloud API key — generate at cloud.acquia.com/a/profile/tokens |
| `ACQUIA_CLI_SECRET` | Acquia Cloud API secret — from the same token page |
| `ACQUIA_GIT_URL` | `eeschandan1@svn-15816.prod.hosting.acquia.com:eeschandan1.git` |
| `ACQUIA_APP_UUID` | The application UUID — find it in `acli api:applications:list` or Acquia Cloud UI |
| `ACQUIA_SSH_PRIVATE_KEY` | Full contents of `~/.ssh/acquia_github_actions` (the private key file) |
| `ACQUIA_SSH_PASSPHRASE` | The passphrase used when generating the key (omit this secret entirely if no passphrase was set) |
| `ACQUIA_DEV_ENV_UUID` | *(Optional)* The dev environment compound ID — avoids an API lookup on every deploy |

**All secrets are repository-level secrets — not environment-level secrets.** The same Acquia git remote and API credentials are used regardless of which environment (dev/test/prod) is being deployed to.

To find `ACQUIA_DEV_ENV_UUID`:
```bash
acli api:applications:environment-list <app-uuid> | python3 -c "
import json,sys
for e in json.load(sys.stdin):
    if e['name']=='dev': print(e['id']); break
"
```

### Step 4 — Create GitHub Environments

Go to **GitHub repo → Settings → Environments** and create three environments:

| Environment name | Required reviewers | Purpose |
|---|---|---|
| `acquia-dev` | None | Auto-deploys on every push |
| `acquia-test` | Add yourself / your team | Manual approval gate before staging |
| `acquia-prod` | Add yourself / your team | Manual approval gate before production |

The environment names must match exactly — the `deploy.yml` workflow constructs the name as `acquia-<input>`.

### Step 5 — Verify the connection

1. Go to **GitHub repo → Actions → Connection Test**
2. Click **Run workflow → Run workflow**
3. All 5 steps should go green
4. If step 3 (`git ls-remote`) fails with `Permission denied (publickey)`:
   - Check the public key was uploaded to Acquia (not the private key)
   - Wait a few minutes for Acquia to propagate the key
   - Use the output of the `Show loaded public key` step to confirm the key in the agent matches the one in Acquia Cloud UI

---

## Day-to-Day Usage

### Normal development push

```bash
git push origin master
```

This automatically triggers the full pipeline: Build → Deploy Dev. Test and Prod then wait for manual approval in the GitHub Actions UI.

### Approving a deployment to test or prod

1. Go to **GitHub repo → Actions → Pipeline**
2. Find the run in progress
3. Click on the `Deploy › Test` or `Deploy › Prod` job
4. Click **Review deployments → Approve and deploy**

### Manual deploy to a specific environment

Useful for hotfixes or re-deploying without a new commit:

1. Go to **GitHub repo → Actions → Pipeline**
2. Click **Run workflow**
3. Choose the target environment (`dev`, `test`, or `prod`) and source branch
4. Click **Run workflow**

### What happens on Acquia after a deploy

Once the deploy workflow pushes to Acquia git and switches the environment branch, Acquia's `post-code-deploy` hook runs automatically on the server:

1. Clears conflicting shortcut entities
2. Writes `social_auth_google` config from `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` environment variables
3. `drush config:import` — syncs `config/default/` into the database
4. `drush updatedb` — runs any pending database updates
5. `drush cache:rebuild` + page cache flush
6. Creates CSS/JS aggregate directories
7. Fires a warm-up HTTP request to pre-generate CSS/JS aggregates

---

## Secret Reference Summary

| Secret | Required | Description |
|---|---|---|
| `ACQUIA_CLI_KEY` | Yes | Acquia Cloud API key (account-level) |
| `ACQUIA_CLI_SECRET` | Yes | Acquia Cloud API secret (account-level) |
| `ACQUIA_GIT_URL` | Yes | Acquia git remote URL for the application |
| `ACQUIA_APP_UUID` | Yes | Acquia application UUID |
| `ACQUIA_SSH_PRIVATE_KEY` | Yes | RSA private key for SSH auth to Acquia git |
| `ACQUIA_SSH_PASSPHRASE` | No | Passphrase for the SSH private key (if set) |
| `ACQUIA_DEV_ENV_UUID` | No | Dev environment compound ID — speeds up dev deploys |

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `Permission denied (publickey)` on git push | Wrong key uploaded to Acquia, or key not propagated yet | Verify public key in Acquia Cloud UI matches `ssh-add -L` output in Actions log. Wait 5 min for propagation. |
| SSH agent prompts for passphrase | `ACQUIA_SSH_PASSPHRASE` secret missing or wrong | Add/correct the `ACQUIA_SSH_PASSPHRASE` secret in GitHub |
| `acli auth:login` fails | Wrong `ACQUIA_CLI_KEY` or `ACQUIA_CLI_SECRET` | Regenerate token at cloud.acquia.com/a/profile/tokens |
| `Could not find environment ID` | API key doesn't have access to the application | Ensure the Acquia token belongs to a user with access to the application |
| `The --branch option does not exist` | Old workflow using `--branch=` flag with `acli code-switch` | Pass the branch as a positional arg: `acli api:environments:code-switch "${ENV_ID}" "${BRANCH}"` |
| Site returns 404 on all paths except `/` | `.htaccess` missing from deployed artifact | Ensure the build step restores `.htaccess` from `docroot/core/assets/scaffold/files/htaccess` and that `include-hidden-files: true` is set on `upload-artifact` |
| Site returns 500 after deploy | `vendor/` directory missing on server | Ensure the deploy step deletes all `.gitignore` files before `git add -A` so `vendor/` is committed to the Acquia git branch |
| `Failed opening required .../composer/autoload_real.php` | `vendor/autoload.php` was copied to `docroot/autoload.php` | The correct `docroot/autoload.php` is a shim: `<?php return require __DIR__ . '/../vendor/autoload.php';` — not a copy of `vendor/autoload.php` |
| Build fails on Tailwind step | `package-lock.json` out of date | Run `npm install` in `docroot/themes/custom/coffee_journal` and commit the updated lock file |
| `phpcs` fails | Coding standards violation in custom code | Run `vendor/bin/phpcs docroot/modules/custom` locally and fix reported issues |

---

## Key Differences from Acquia Pipelines

If you previously used Acquia Pipelines (`acquia-pipelines.yml`), here are the main differences:

| | Acquia Pipelines | GitHub Actions |
|---|---|---|
| **Trigger** | Push to Acquia git remote | Push to GitHub remote |
| **Scaffold files** | Auto-injected by Acquia | Must be restored manually in build step |
| **`vendor/`** | Auto-included | Must remove `.gitignore` files before committing |
| **Deploy branch** | Created automatically | Pushed explicitly by the workflow |
| **Approval gates** | Not supported | GitHub Environments with required reviewers |
| **Build logs** | Acquia Cloud UI | GitHub Actions UI |
