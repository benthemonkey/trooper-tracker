# Midwest Garrison staging

A copy of trooper-tracker on the Midwest DreamHost account, filled with real data from the live
legacy (TT1.0) tracker and rebuilt from it every night. Changes made on staging don't survive the
next rebuild. It can read the live forum (OAuth login) but never writes to it: no XenForo API key,
plus `XENFORO_READ_ONLY=true`. Mail goes to the log.

| File | Goes to |
|---|---|
| `tt-staging-refresh.sh` | run in place from the clone (cron) |
| `tt-staging-update.sh` | run in place from the clone, to deploy new code |
| `env.staging.example` | `~/trooper-tracker/tracker-app/.env` (chmod 600) |
| `legacy-db.cnf.example` | `~/.tt-legacy-db.cnf` (chmod 600) |
| `legacy-uploads.htaccess` | `tracker-app/public/images/uploads/.htaccess` |
| `crontab.example` | the DreamHost panel's Cron Jobs (or `crontab -e`) |
| `check-cron.sh` | run in place from the clone, to test the cron jobs |

## How the rebuild works

Two databases, `mwg_tt_test_a` and `mwg_tt_test_b`. `.env` points at the live one. The script:

1. takes a lock, works out the idle DB, and refuses any name not matching `^mwg_tt_test_[ab]$`;
2. clears the config cache and checks artisan resolves the idle DB (it passes `DB_DATABASE` as an
   environment variable, which beats `.env`), then `migrate:fresh` on it;
3. copies the legacy DB in with `mysqldump --single-transaction` (a consistent read, no locks on
   the live site);
4. runs `MidwestGarrisonSeeder`;
5. checks troopers, events, sign-ups and guests against the legacy tables, then drops the legacy
   tables from the staging DB;
6. points `.env` at the rebuilt DB, clears the cache, restarts the queue, and deletes files
   uploaded to staging since the last run.

Any failure stops before step 6, so the site keeps serving the previous copy. Output goes to
`~/tt-staging-logs/tt-staging-refresh.log`; only a failure prints to stderr, so cron mails only then.

Settings are environment variables with Midwest defaults (`TT_PHP`, `TT_LEGACY_DB`,
`TT_DB_A`/`TT_DB_B`, `TT_PHP_MEMORY`, `TT_MYSQLDUMP_OPTS`, ...; see the top of the script).
`TT_KEEP_LEGACY=1` keeps the legacy tables for debugging. Don't run `config:cache` on staging.

## Install

1. `git clone -b mwg/staging https://github.com/benthemonkey/trooper-tracker.git ~/trooper-tracker`
   (before step 3: the panel creates the web directory, and git won't clone into a non-empty one)
2. `cd ~/trooper-tracker/tracker-app && composer install --no-dev --optimize-autoloader`
3. DreamHost panel: subdomain with web directory `~/trooper-tracker/tracker-app/public`, PHP 8.4,
   Let's Encrypt. MySQL: databases `mwg_tt_test_a` and `mwg_tt_test_b` with one new user for
   both, and a Select-only user on `mwg_troop_tracker`.
4. Upload the prebuilt `public/build/` (built locally; the server needs no Node).
5. Write `.env` and `~/.tt-legacy-db.cnf` from the examples, then `php artisan key:generate` and
   `php artisan storage:link`.
6. Legacy photos: `cp ../deploy/staging/legacy-uploads.htaccess public/images/uploads/.htaccess`
   (redirects them to the live site; staging's own uploads go to `storage/app/public`).
7. Run `deploy/staging/tt-staging-refresh.sh` by hand, time it, read the log.
8. Add the crontab lines.

## Update

Build `public/build` somewhere with Node, stamp it with the commit (`git rev-parse HEAD >
public/build/.commit`), tar it as `build/...`, copy it to the server, then:

    ~/trooper-tracker/deploy/staging/tt-staging-update.sh ~/tt-build.tar.gz [--refresh]

It pulls, runs `composer install`, swaps in the build, migrates the live staging DB, clears caches
and restarts the queue. When `.env` has `SENTRY_DSN` or `SENTRY_BROWSER_DSN`, the Composer step
uses upstream's plugin deploy tooling (`tracker-app/bin/plugin-packages.sh`) instead, so the
Sentry plugin's package is installed and the plugin turned on; if that package can't be
installed, it deploys without it and says so. The nightly refresh turns the plugin on in each
rebuilt copy when a DSN is set. The local rehearsal has no DSN, so Sentry stays off there. It warns if the front end changed since the build it is serving.
`--refresh` also rebuilds the data now. To roll back: `git checkout <sha>`, then run it with
`TT_NO_PULL=1` and a build of that commit. Midwest's workspace has `deploy-staging.sh`, which does
all of it from Windows.
