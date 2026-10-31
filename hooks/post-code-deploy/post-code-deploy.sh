#!/usr/bin/env bash
# Acquia post-code-deploy: update the deployed application without changing
# account policy or enabling optional modules merely because they are present.
set -euo pipefail

SITE="$1"
ENV="$2"
BRANCH="$3"
DEPLOY_TYPE="$5"

log() { echo "[post-code-deploy][${SITE}.${ENV}] $*"; }

if [[ "${DEPLOY_TYPE}" == "files" || "${DEPLOY_TYPE}" == "db" ]]; then
  log "Skipping Drush for ${DEPLOY_TYPE}-only deployment"
  exit 0
fi

if [[ "${ENV}" == "prod" ]]; then
  DOCROOT="/mnt/www/html/${SITE}/docroot"
  SITE_URL="https://${SITE}.prod.acquia-sites.com"
else
  DOCROOT="/mnt/www/html/${SITE}${ENV}/docroot"
  SITE_URL="https://${SITE}${ENV}.prod.acquia-sites.com"
fi
# Cloud Next exposes the selected environment at this path.
if [[ ! -d "${DOCROOT}" && -d /var/www/html/docroot ]]; then
  DOCROOT=/var/www/html/docroot
fi

DRUSH_SCRIPT="${DOCROOT}/../vendor/drush/drush/drush.php"
PHP_BIN="$(command -v php)"
DRUSH=("${PHP_BIN}" "${DRUSH_SCRIPT}" "--root=${DOCROOT}" "--uri=${SITE_URL}")
test -f "${DRUSH_SCRIPT}"
log "Deploying ${BRANCH}; docroot ${DOCROOT}"

# Enable only custom modules explicitly selected in exported configuration.
# In particular, coffee_journal_auth remains optional and is not activated here.
CONFIGURED_MODULES="$("${DRUSH[@]}" php:eval '
$file = DRUPAL_ROOT . "/../config/default/core.extension.yml";
$config = \Symfony\Component\Yaml\Yaml::parseFile($file);
$modules = [];
foreach (array_keys($config["module"] ?? []) as $module) {
  if (is_file(DRUPAL_ROOT . "/modules/custom/" . $module . "/" . $module . ".info.yml")) {
    $modules[] = $module;
  }
}
print implode(" ", $modules);
')"
if [[ -n "${CONFIGURED_MODULES}" ]]; then
  read -r -a MODULES <<< "${CONFIGURED_MODULES}"
  "${DRUSH[@]}" pm:enable --yes "${MODULES[@]}"
fi

# Arrays preserve executable/argument boundaries. Any failure stops promotion.
"${DRUSH[@]}" updatedb --yes
"${DRUSH[@]}" config:import --yes
"${DRUSH[@]}" cache:rebuild

curl --silent --show-error --output /dev/null --max-time 30 "${SITE_URL}/user/login" \
  || log "Asset warm-up failed; assets will generate on the next request"
log "Deploy hook completed successfully"
