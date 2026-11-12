#!/bin/bash
#
# Update the staging site to the latest mwg/staging: pull, composer install, swap in a new
# front-end build, migrate the live staging DB, clear caches, restart the queue.
#
#   tt-staging-update.sh [BUILD.tar.gz] [--refresh]
#
# BUILD.tar.gz is a tarball of public/build made locally (the server has no Node; the Midwest
# workspace's deploy-staging.sh builds, uploads and runs this). Without it the current build stays,
# with a warning when the front end changed since it was built. --refresh also rebuilds the data
# now rather than at the nightly run, and skips migrating the live DB, which it replaces. If that
# refresh fails, the new code is left running on the unmigrated DB until a refresh succeeds.
# TT_NO_PULL=1 deploys whatever is checked out, e.g. after
# `git checkout <sha>` to roll back.
#
set -Eeuo pipefail

REPO_DIR="${TT_REPO_DIR:-$HOME/trooper-tracker}"
if [ -z "${TT_PHP:-}" ] && [ -x /usr/local/php84/bin/php ]
then
    TT_PHP=/usr/local/php84/bin/php
fi
PHP="${TT_PHP:-php}"
export TT_PHP="$PHP"
COMPOSER="${TT_COMPOSER:-$HOME/bin/composer.phar}"
# Same lock as tt-staging-refresh.sh: never update code under a running rebuild
LOCK_FILE="${TT_LOCK_FILE:-$HOME/.tt-staging-refresh.lock}"
DB_PATTERN="${TT_DB_PATTERN:-^mwg_tt_test_[ab]$}"
NO_PULL="${TT_NO_PULL:-0}"
# Paths that feed the Vite build (Tailwind also scans the Blade views)
FRONT_END_PATHS=(tracker-app/resources tracker-app/package.json tracker-app/package-lock.json tracker-app/vite.config.js)

say() { printf '==> %s\n' "$*"; }
die() { printf 'tt-staging-update failed: %s\n' "$*" >&2; exit 1; }

# Sentry is on where .env has a DSN: the server, not the local rehearsal (tt-staging-refresh.sh
# turns it on in each rebuilt copy). Run from tracker-app.
wants_sentry() { grep -qE "^SENTRY_(BROWSER_)?DSN=[\"']?[^\"' ]" .env; }

# The app's packages plus the Sentry plugin's, with upstream's deploy tooling
# (bin/plugin-packages.sh, as deploy.sh uses it): turn the plugin on in the live DB, where it
# waits until its package is installed, save what enabled plugins need, install it all in one
# Composer run (core packages held at composer.lock), then turn on what was waiting. If the
# plugin package can't be installed, the site still deploys without it.
install_with_sentry()
{
    local PLUGIN_DEPLOY_DIR="$PWD/storage/app/plugin-deploy" COMPOSER_BIN="$COMPOSER"
    mkdir -p "$PLUGIN_DEPLOY_DIR"
    export COMPOSER_MEMORY_LIMIT=1G

    say "plugin packages: enable sentry, list what plugins need"
    "$PHP" artisan config:clear --no-interaction
    "$PHP" artisan plugins:enable sentry --no-interaction \
        && "$PHP" artisan plugins:packages --write --no-interaction \
        || say "WARNING: could not update the plugin package list; using the saved one"

    # shellcheck source=../../tracker-app/bin/plugin-packages.sh
    . bin/plugin-packages.sh
    backup_plugin_packages
    if ! install_packages
    then
        say "WARNING: plugin packages failed (see $PLUGIN_COMPOSER_LOG); installing without them"
        rm -f composer.plugins.json composer.plugins.lock
        "$PHP" -d memory_limit=1G "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress
    fi

    "$PHP" artisan plugins:reconcile --no-interaction
}

# All in a function: bash reads a script as it runs, and the git pull below can rewrite this file
main()
{
    local build="" refresh=0 arg

    for arg in "$@"
    do
        case "$arg" in
            --refresh) refresh=1 ;;
            -*) die "unknown option $arg" ;;
            *) build="$arg" ;;
        esac
    done

    if [ -n "$build" ]
    then
        [ -f "$build" ] || die "no build tarball at $build"
    fi

    exec 9>"$LOCK_FILE"
    flock -n 9 || die "a refresh or update is running"

    cd "$REPO_DIR"

    if [ "$NO_PULL" != 1 ]
    then
        say "git pull"
        git pull --ff-only -q
    fi

    say "at $(git log -1 --format='%h %s')"

    cd tracker-app

    local db
    db=$(grep -E '^DB_DATABASE=' .env | tail -n 1 | cut -d= -f2- | tr -d "\"'")
    [[ "$db" =~ $DB_PATTERN ]] || die ".env DB_DATABASE ($db) does not match $DB_PATTERN"

    if wants_sentry
    then
        install_with_sentry
    else
        say "composer install"
        rm -f composer.plugins.json composer.plugins.lock
        "$PHP" -d memory_limit=1G "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress
    fi

    if [ -n "$build" ]
    then
        say "front-end build"
        local unpacked
        unpacked=$(mktemp -d storage/framework/build-XXXXXX)
        tar xzf "$build" -C "$unpacked"
        [ -f "$unpacked/build/manifest.json" ] || die "$build has no build/manifest.json"
        rm -rf public/build.old
        if [ -d public/build ]
        then
            mv public/build public/build.old
        fi
        mv "$unpacked/build" public/build
        rm -rf public/build.old "$unpacked" "$build"
    fi

    local built
    built=$(cat public/build/.commit 2>/dev/null || true)
    if [ -z "$built" ]
    then
        say "WARNING: the front-end build has no commit stamp; can't tell whether it is current"
    elif ! git -C "$REPO_DIR" diff --quiet "$built" HEAD -- "${FRONT_END_PATHS[@]}" 2>/dev/null
    then
        say "WARNING: the front end changed since the build (${built:0:8}); upload a new one"
    fi

    # The refresh builds a new copy from scratch, so migrating this one is wasted work, and a
    # migration that refuses to run on old data would stop the deploy before the refresh starts.
    if [ "$refresh" = 1 ]
    then
        say "skip migrating $db: the refresh replaces it"
    else
        say "migrate $db"
        "$PHP" artisan config:clear --no-interaction
        "$PHP" artisan migrate --force --no-interaction
    fi

    say "clear caches, restart queue"
    "$PHP" artisan optimize:clear --no-interaction
    "$PHP" artisan queue:restart --no-interaction

    if [ "$refresh" = 1 ]
    then
        flock -u 9
        say "refresh data (log: ~/tt-staging-logs/tt-staging-refresh.log)"
        TT_APP_DIR="$REPO_DIR/tracker-app" "$REPO_DIR/deploy/staging/tt-staging-refresh.sh"
    fi

    say "done"
}

main "$@"
exit
