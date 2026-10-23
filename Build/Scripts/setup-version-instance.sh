#!/usr/bin/env bash
#
# Build a clickable, pinned TYPO3 demo for one major version, in its own DDEV
# volume (/var/www/html/v<MAJOR>), with EXT:extendredirect wired in via a Composer
# path repository pointing at the working tree. Re-runnable: pass --fresh to wipe
# and rebuild from scratch.
#
#   bash Build/Scripts/setup-version-instance.sh 13
#   bash Build/Scripts/setup-version-instance.sh 14 --fresh
#
# Invoked by `ddev install-v13` / `ddev install-v14`. The demo content itself is
# seeded by setup-demo-instance.sh, which this script calls with volume paths.

set -euo pipefail

MAJOR="${1:?usage: setup-version-instance.sh <13|14> [--fresh]}"
FRESH="${2:-}"

case "${MAJOR}" in
    13) TYPO3_CONSTRAINT="^13.4" ;;
    14) TYPO3_CONSTRAINT="^14.3" ;;
    *) echo "Unsupported major '${MAJOR}' (expected 13 or 14)"; exit 1 ;;
esac

REPO_ROOT="/var/www/html"
INSTALL_DIR="${REPO_ROOT}/v${MAJOR}"
DB_NAME="db_v${MAJOR}"
# Derive the host from the DDEV project name so worktrees with a custom name (set
# via .ddev/config.local.yaml) get the matching v<major>.<name>.ddev.site base.
BASE_URL="https://v${MAJOR}.${DDEV_SITENAME:-extendredirect}.ddev.site/"

# Docker volumes mount as root; take ownership so Composer can write.
sudo chown "$(id -u):$(id -g)" "${INSTALL_DIR}"

if [ "${FRESH}" = "--fresh" ]; then
    echo "› --fresh: wiping ${INSTALL_DIR}"
    rm -rf "${INSTALL_DIR:?}"/* "${INSTALL_DIR}"/.[!.]* 2>/dev/null || true
fi

# IMPORTANT: cd into the (empty) install dir BEFORE create-project and target ".".
# Running create-project with the repo root as CWD makes Composer load the parent
# project's plugins (an older typo3/class-alias-loader), which then clashes with the
# base distribution's newer copy during the autoload dump:
#   Call to undefined method ClassAliasMapGenerator::modifyComposerGeneratedFiles()
cd "${INSTALL_DIR}"

if [ ! -f composer.json ]; then
    echo "=== Creating TYPO3 ${TYPO3_CONSTRAINT} base distribution in ${INSTALL_DIR} ==="
    # Recover from a half-finished previous run: an existing composer.json short-circuits,
    # but stray files would make create-project refuse the non-empty target.
    rm -rf "${INSTALL_DIR:?}"/* "${INSTALL_DIR}"/.[!.]* 2>/dev/null || true
    composer create-project "typo3/cms-base-distribution:${TYPO3_CONSTRAINT}" . --no-interaction --no-progress
fi

echo "› wiring EXT:extendredirect from the working tree via a path repository"
composer config repositories.extendredirect path "${REPO_ROOT}"
composer config minimum-stability dev
composer config prefer-stable true
# The base distribution pins config.platform.php to 8.2.0 for broad dependency
# compatibility, but EXT:extendredirect requires >=8.3. Align the platform with the
# container's actual PHP so the extension resolves.
composer config platform.php "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;')"
# helhum/typo3-console provides the configuration:set / asset:publish commands setup-demo-instance.sh calls.
composer require "nivek1110/extendredirect:*" typo3/cms-fluid-styled-content:"${TYPO3_CONSTRAINT}" "helhum/typo3-console:^9.0" --no-interaction --no-progress

echo "› granting the ddev db user access to ${DB_NAME}"
mysql -h db -u root -proot -e \
    "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`; GRANT ALL ON \`${DB_NAME}\`.* TO 'db'@'%';"

echo "› seeding the demo content"
TYPO3_BIN="vendor/bin/typo3" \
DB_NAME="${DB_NAME}" \
DB_USER="db" DB_PASS="db" \
BASE_URL="${BASE_URL}" \
    bash "${REPO_ROOT}/Build/Scripts/setup-demo-instance.sh"

echo ""
echo "=== TYPO3 v${MAJOR} demo ready: ${BASE_URL} (backend ${BASE_URL}typo3/, user admin - password printed above) ==="
