#!/usr/bin/env bash
# =============================================================================
# Acquia Cloud Hook: post-code-deploy
# =============================================================================
# Triggered automatically by Acquia whenever a code switch or deployment
# completes on any environment (dev, test/stage, prod).
#
# Arguments passed by Acquia (in order):
#   $1  site name       (e.g. eeschandan1)
#   $2  environment     (e.g. dev, test, prod)
#   $3  deploy branch   (e.g. pipelines-build-master)
#   $4  previous branch (branch being replaced)
#   $5  deploy type     (code, code-files, files, db)
#
# What this hook does:
#   1. Resolves the docroot path for the environment
#   2. Imports any config changes from config/default/ into the database
#   3. Runs a full Drupal cache rebuild
#   4. Logs success/failure for Acquia Cloud UI
#
# NOTE: This hook runs as the web-server user (www-data or similar) and has
# the same file-system permissions as the deployed codebase.
# =============================================================================

set -euo pipefail

SITE="$1"
ENV="$2"
BRANCH="$3"
OLD_BRANCH="$4"
DEPLOY_TYPE="$5"

# Acquia docroot path pattern: /mnt/www/html/<site><env>/docroot
DOCROOT="/mnt/www/html/${SITE}${ENV}/docroot"
DRUSH="${DOCROOT}/../vendor/bin/drush"

log() {
  echo "[post-code-deploy][${SITE}.${ENV}] $*"
}

log "Deploy started — branch: ${BRANCH} (was: ${OLD_BRANCH}), type: ${DEPLOY_TYPE}"

# Only run Drush steps on code deployments (not files-only or db-only).
if [[ "${DEPLOY_TYPE}" == "files" || "${DEPLOY_TYPE}" == "db" ]]; then
  log "Deploy type is '${DEPLOY_TYPE}' — skipping Drush steps"
  exit 0
fi

if [[ ! -f "${DRUSH}" ]]; then
  log "WARNING: Drush not found at ${DRUSH} — trying system drush"
  DRUSH="drush"
fi

log "Docroot: ${DOCROOT}"
log "Drush:   ${DRUSH}"

# ── Step 1: Clear shortcut entities if they conflict with config ─────────────
log "Clearing conflicting shortcut entities..."
"${DRUSH}" ev "
\$shortcuts = \Drupal::entityTypeManager()->getStorage('shortcut')->loadMultiple();
foreach (\$shortcuts as \$s) { \$s->delete(); }
\$sets = \Drupal::entityTypeManager()->getStorage('shortcut_set')->loadMultiple();
foreach (\$sets as \$s) { \$s->delete(); }
echo count(\$shortcuts) . ' shortcuts and ' . count(\$sets) . ' sets deleted';
" 2>&1 || log "Note: shortcut cleanup skipped (may not be needed)"

# ── Step 2: Write social_auth_google config from env vars ────────────────────
# social_auth_google ships no config/install defaults, so the config object
# must be written explicitly. The $config[] override in settings.php only works
# when the object already exists. This step ensures it exists on every deploy.
log "Writing social_auth_google config from environment variables..."
if [[ -n "${GOOGLE_CLIENT_ID:-}" && -n "${GOOGLE_CLIENT_SECRET:-}" ]]; then
  "${DRUSH}" ev "
\Drupal::configFactory()->getEditable('social_auth_google.settings')
  ->set('client_id',         getenv('GOOGLE_CLIENT_ID'))
  ->set('client_secret',     getenv('GOOGLE_CLIENT_SECRET'))
  ->set('scopes',            '')
  ->set('endpoints',         '')
  ->set('restricted_domain', '')
  ->save();
echo 'social_auth_google.settings written';
" 2>&1 && log "social_auth_google config written" \
    || log "WARNING: could not write social_auth_google config"
else
  log "WARNING: GOOGLE_CLIENT_ID or GOOGLE_CLIENT_SECRET not set — social auth will not work"
fi

# ── Step 3: Config import ────────────────────────────────────────────────────
log "Running drush config:import..."
cd "${DOCROOT}"
"${DRUSH}" config:import --yes 2>&1 && log "config:import succeeded" \
  || log "WARNING: config:import had warnings (may be a no-op if config is already in sync)"

# ── Step 4: Database updates ─────────────────────────────────────────────────
log "Running drush updatedb..."
"${DRUSH}" updatedb --yes 2>&1 && log "updatedb succeeded" \
  || log "WARNING: updatedb had warnings"

# ── Step 5: Cache rebuild ────────────────────────────────────────────────────
log "Running drush cache:rebuild..."
"${DRUSH}" cache:rebuild 2>&1 && log "cache:rebuild succeeded" \
  || { log "ERROR: cache:rebuild failed"; exit 1; }

# Explicitly flush the page cache bin which cache:rebuild sometimes preserves
"${DRUSH}" ev "\Drupal::cache('page')->deleteAll(); echo 'page cache flushed';" 2>&1 \
  && log "page cache flushed" || log "Note: page cache flush skipped"

log "Deploy hook completed successfully"
