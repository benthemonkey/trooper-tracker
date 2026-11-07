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
# now rather than at the nightly run. TT_NO_PULL=1 deploys whatever is checked out, e.g. after
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

    say "composer install"
    "$PHP" -d memory_limit=1G "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

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

    say "migrate $db"
    "$PHP" artisan config:clear --no-interaction
    "$PHP" artisan migrate --force --no-interaction

    # Forum login comes from this plugin. The refresh enables it on each new copy, but a deploy can
    # migrate a copy that doesn't have it yet, which would leave the site with no forum login.
    say "enable xenforo plugin"
    "$PHP" artisan plugins:enable xenforo --no-interaction

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
