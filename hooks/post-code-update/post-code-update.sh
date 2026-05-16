#!/usr/bin/env bash
# =============================================================================
# Acquia Cloud Hook: post-code-update
# =============================================================================
# Triggered when code is updated (git pull) on an environment.
# Identical in purpose to post-code-deploy — runs config import and cache
# rebuild to keep Drupal in sync with the new codebase.
#
# Arguments: same as post-code-deploy
# =============================================================================

set -euo pipefail

SITE="$1"
ENV="$2"

# Acquia docroot path pattern:
#   dev / test:  /mnt/www/html/<site><env>/docroot  (e.g. eeschandan1dev, eeschandan1test)
#   prod:        /mnt/www/html/<site>/docroot        (e.g. eeschandan1 — no env suffix)
if [[ "${ENV}" == "prod" ]]; then
  DOCROOT="/mnt/www/html/${SITE}/docroot"
else
  DOCROOT="/mnt/www/html/${SITE}${ENV}/docroot"
fi
PHP_BIN="/usr/local/php8.4/bin/php"
DRUSH_SCRIPT="${DOCROOT}/../vendor/drush/drush/drush.php"
DRUSH="${PHP_BIN} ${DRUSH_SCRIPT} --root=${DOCROOT}"

log() {
  echo "[post-code-update][${SITE}.${ENV}] $*"
}

log "Code update detected — running Drush post-update steps"

if [[ ! -f "${DRUSH_SCRIPT}" ]]; then
  log "WARNING: drush.php not found at ${DRUSH_SCRIPT} — trying system drush"
  DRUSH="drush --root=${DOCROOT}"
fi

cd "${DOCROOT}"

log "Running drush config:import..."
"${DRUSH}" config:import --yes 2>&1 && log "config:import OK" \
  || log "WARNING: config:import had issues"

log "Running drush updatedb..."
"${DRUSH}" updatedb --yes 2>&1 && log "updatedb OK" \
  || log "WARNING: updatedb had issues"

log "Running drush cache:rebuild..."
"${DRUSH}" cache:rebuild 2>&1 && log "cache:rebuild OK" \
  || { log "ERROR: cache:rebuild failed"; exit 1; }

log "Post-code-update completed"
