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

# Acquia docroot path pattern:
#   dev / test:  /mnt/www/html/<site><env>/docroot  (e.g. eeschandan1dev, eeschandan1test)
#   prod:        /mnt/www/html/<site>/docroot        (e.g. eeschandan1 — no env suffix)
if [[ "${ENV}" == "prod" ]]; then
  DOCROOT="/mnt/www/html/${SITE}/docroot"
else
  DOCROOT="/mnt/www/html/${SITE}${ENV}/docroot"
fi
# Use php directly to invoke drush.php — vendor/bin/drush is a shell script
# shim that may not have execute permission on Acquia Cloud's read-only FS.
PHP_BIN="/usr/local/php8.4/bin/php"
DRUSH_SCRIPT="${DOCROOT}/../vendor/drush/drush/drush.php"
DRUSH="${PHP_BIN} ${DRUSH_SCRIPT} --root=${DOCROOT}"

log() {
  echo "[post-code-deploy][${SITE}.${ENV}] $*"
}

log "Deploy started — branch: ${BRANCH} (was: ${OLD_BRANCH}), type: ${DEPLOY_TYPE}"

# Only run Drush steps on code deployments (not files-only or db-only).
if [[ "${DEPLOY_TYPE}" == "files" || "${DEPLOY_TYPE}" == "db" ]]; then
  log "Deploy type is '${DEPLOY_TYPE}' — skipping Drush steps"
  exit 0
fi

if [[ ! -f "${DRUSH_SCRIPT}" ]]; then
  log "WARNING: drush.php not found at ${DRUSH_SCRIPT} — trying system drush"
  DRUSH="drush --root=${DOCROOT}"
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

# ── Step 2.5: Enable all custom modules before config import ─────────────────
# drush pm:enable is idempotent — already-enabled modules are silently skipped.
# Running this BEFORE drush cim is critical: if a new module is added to
# core.extension.yml but not yet installed in the DB, Drupal's bootstrap will
# fail when it tries to load the service container during cim (because an
# already-installed module declares the new one as a hard dependency). Enabling
# custom modules first ensures hook_schema tables are created and the service
# container can compile cleanly before cim runs.
log "Enabling all custom modules..."
CUSTOM_MODS=$(ls -d "${DOCROOT}/modules/custom/"*/ 2>/dev/null \
  | xargs -I{} basename {} \
  | tr '\n' ' ')
if [[ -n "${CUSTOM_MODS}" ]]; then
  log "Custom modules found: ${CUSTOM_MODS}"
  "${DRUSH}" pm:enable --yes ${CUSTOM_MODS} 2>&1 \
    && log "Custom modules enabled (or already enabled)" \
    || log "WARNING: Some custom modules could not be enabled — check logs"
else
  log "No custom modules found in ${DOCROOT}/modules/custom/"
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

# ── Step 6: Ensure CSS/JS aggregate directories exist ───────────────────────
# Drupal uses CssCollectionOptimizerLazy — it writes CSS aggregates to
# public://css/ on the FIRST request for each file after a cache rebuild.
# If that directory doesn't exist, the write silently fails and the page
# renders unstyled. The fast_404 module also blocks missing .css/.js files
# unless /files/css/ and /files/js/ are excluded in its exclude_paths config.
log "Ensuring CSS/JS aggregate directories exist..."
PUBLIC_FILES="${DOCROOT}/sites/default/files"
if [[ -L "${PUBLIC_FILES}" ]]; then
  PUBLIC_FILES="$(readlink -f "${PUBLIC_FILES}")"
fi
mkdir -p "${PUBLIC_FILES}/css" "${PUBLIC_FILES}/js"
chmod 2775 "${PUBLIC_FILES}/css" "${PUBLIC_FILES}/js" 2>/dev/null || true
log "CSS dir: ${PUBLIC_FILES}/css"
log "JS dir:  ${PUBLIC_FILES}/js"

# ── Step 7: Warm CSS/JS aggregates via an internal HTTP request ──────────────
# Makes one GET to the login page (anonymous, no side effects) so that Drupal
# renders a full page and triggers the lazy CSS/JS optimizer to write the
# aggregate files to disk before the first real user hits the site.
log "Warming CSS/JS aggregates with internal request..."
SITE_URL="https://${SITE}${ENV}.prod.acquia-sites.com"
if [[ "${ENV}" == "prod" ]]; then
  SITE_URL="https://${SITE}.prod.acquia-sites.com"
fi
curl -sSL -o /dev/null --max-time 30 "${SITE_URL}/user/login" \
  && log "CSS/JS warm-up request succeeded (${SITE_URL}/user/login)" \
  || log "Note: CSS/JS warm-up request failed — CSS will be generated on first real user request"

log "Deploy hook completed successfully"
