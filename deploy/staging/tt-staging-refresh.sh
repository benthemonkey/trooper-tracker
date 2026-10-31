#!/bin/bash
#
# Rebuild the trooper-tracker staging copy from the live legacy (TT1.0) tracker database.
#
# Imports into whichever of TT_DB_A / TT_DB_B the site is NOT using, checks the result, then
# points .env at it. A failure at any step leaves the current copy live, untouched. Anything
# changed on staging since the last run is discarded. Run nightly from cron, or by hand for an
# on-demand refresh. Setup: deploy/staging/README.md.
#
# Output goes to TT_LOG_FILE. Only a failure prints to stderr, so cron mails only then.
#
set -Eeuo pipefail

APP_DIR="${TT_APP_DIR:-$HOME/trooper-tracker/tracker-app}"
PHP="${TT_PHP:-php}"
PHP_MEMORY="${TT_PHP_MEMORY:-1024M}"
# Not ~/logs: DreamHost owns that folder (web server logs) and it is read-only
LOG_FILE="${TT_LOG_FILE:-$HOME/tt-staging-logs/tt-staging-refresh.log}"
LOCK_FILE="${TT_LOCK_FILE:-$HOME/.tt-staging-refresh.lock}"
# [client] host/user/password with read access to the legacy DB (see legacy-db.cnf.example)
LEGACY_CNF="${TT_LEGACY_CNF:-$HOME/.tt-legacy-db.cnf}"
LEGACY_DB="${TT_LEGACY_DB:-mwg_troop_tracker}"
# Space-separated table list; empty copies the whole legacy DB
LEGACY_TABLES="${TT_LEGACY_TABLES:-}"
MYSQLDUMP_OPTS="${TT_MYSQLDUMP_OPTS:-}"
# Extra [client] option-file lines for the staging DB connection, e.g. "skip-ssl-verify-server-cert"
MYSQL_CNF_EXTRA="${TT_MYSQL_CNF_EXTRA:-}"
DB_A="${TT_DB_A:-mwg_tt_test_a}"
DB_B="${TT_DB_B:-mwg_tt_test_b}"
DB_PATTERN="${TT_DB_PATTERN:-^mwg_tt_test_[ab]$}"
SEEDER="${TT_SEEDER:-MidwestGarrisonSeeder}"
PLACEHOLDER_ID="${TT_PLACEHOLDER_ID:-240}"
# Allowed shortfall of imported shifts and sign-ups vs legacy (duplicate legacy shifts are merged;
# 0 on the 2026-10 data)
SIGNUP_TOLERANCE="${TT_SIGNUP_TOLERANCE:-5}"
KEEP_LEGACY="${TT_KEEP_LEGACY:-0}"
# Test hook for the rehearsal: fail after this step (migrate|copy|seed|check)
FAIL_AFTER="${TT_FAIL_AFTER:-}"

mkdir -p "$(dirname "$LOG_FILE")"
exec 3>&2
exec >>"$LOG_FILE" 2>&1

STAGE_CNF=""

log() { printf '%s %s\n' "$(date '+%F %T')" "$*"; }

fail()
{
    trap - ERR
    log "FAILED: $*"
    printf 'tt-staging-refresh failed: %s (log: %s)\n' "$*" "$LOG_FILE" >&3
    exit 1
}

cleanup() { [ -n "$STAGE_CNF" ] && rm -f "$STAGE_CNF"; }

trap 'fail "line $LINENO: $BASH_COMMAND"' ERR
trap cleanup EXIT

# Value of KEY in .env, without surrounding quotes
env_value()
{
    local line
    line=$(grep -E "^$1=" .env | tail -n 1) || true
    line="${line#*=}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    printf '%s' "$line"
}

# Artisan against the idle DB. A real env var beats .env (Dotenv never overwrites one), which
# only fails if config is cached, so that is ruled out below. Cache and queue are kept off the
# live site's: the import must not leave cached values or queued jobs behind.
idle_artisan()
{
    DB_DATABASE="$IDLE" CACHE_STORE=array QUEUE_CONNECTION=null \
        "$PHP" -d memory_limit="$PHP_MEMORY" artisan "$@" --no-interaction
}

q() { mysql --defaults-extra-file="$STAGE_CNF" -N -B "$IDLE" -e "$1"; }

checkpoint() { [ "$FAIL_AFTER" != "$1" ] || fail "TT_FAIL_AFTER=$1 (rehearsal)"; }

exec 9>"$LOCK_FILE"
flock -n 9 || fail "another refresh is still running"

cd "$APP_DIR"
SECONDS=0

[ -f .env ] || fail "no .env in $APP_DIR"
[ -r "$LEGACY_CNF" ] || fail "cannot read $LEGACY_CNF"
[ "$(env_value DB_CONNECTION)" = mysql ] || fail ".env DB_CONNECTION must be mysql"

ACTIVE=$(env_value DB_DATABASE)
case "$ACTIVE" in
    "$DB_A") IDLE="$DB_B" ;;
    "$DB_B") IDLE="$DB_A" ;;
    *) fail ".env DB_DATABASE ($ACTIVE) is neither $DB_A nor $DB_B" ;;
esac

for db in "$ACTIVE" "$IDLE"
do
    [[ "$db" =~ $DB_PATTERN ]] || fail "$db does not match $DB_PATTERN"
    [ "$db" != "$LEGACY_DB" ] || fail "$db is the legacy database"
done

log "start: live=$ACTIVE, rebuilding $IDLE from $LEGACY_DB"

"$PHP" artisan config:clear --no-interaction
[ ! -f bootstrap/cache/config.php ] || fail "config is still cached"
resolved=$(idle_artisan config:show database.connections.mysql.database)
grep -qw -- "$IDLE" <<< "$resolved" \
    || fail "artisan does not resolve DB_DATABASE to $IDLE; refusing to wipe anything"

STAGE_CNF=$(mktemp)
chmod 600 "$STAGE_CNF"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n%s\n' \
    "$(env_value DB_HOST)" "$(env_value DB_PORT)" "$(env_value DB_USERNAME)" "$(env_value DB_PASSWORD)" \
    "$MYSQL_CNF_EXTRA" > "$STAGE_CNF"

log "migrate:fresh $IDLE"
idle_artisan migrate:fresh --force
checkpoint migrate

log "copy $LEGACY_DB -> $IDLE"
# word splitting of the option and table lists is intended
# shellcheck disable=SC2086
mysqldump --defaults-extra-file="$LEGACY_CNF" --single-transaction --skip-lock-tables --no-tablespaces \
    --skip-triggers $MYSQLDUMP_OPTS "$LEGACY_DB" $LEGACY_TABLES \
    | mysql --defaults-extra-file="$STAGE_CNF" "$IDLE"
checkpoint copy

log "seed $SEEDER"
idle_artisan db:seed --class="$SEEDER" --force
checkpoint seed

log "check counts"
legacy_troopers=$(q "SELECT COUNT(*) FROM troopers")
#   a legacy event row with link > 0 is another shift of the event it links to
legacy_events=$(q "SELECT COUNT(*) FROM events WHERE link = 0")
legacy_shifts=$(q "SELECT COUNT(*) FROM events")
#   what the importer reads: sign-ups whose trooper and shift were imported, one per pair
legacy_signups=$(q "SELECT COUNT(DISTINCT s.troopid, s.trooperid) FROM event_sign_up s
    JOIN tt_troopers t ON t.id = s.trooperid JOIN tt_event_shifts h ON h.id = s.troopid
    WHERE s.trooperid <> $PLACEHOLDER_ID")
legacy_guests=$(q "SELECT COUNT(*) FROM event_sign_up s JOIN tt_event_shifts h ON h.id = s.troopid
    WHERE s.trooperid = $PLACEHOLDER_ID")
troopers=$(q "SELECT COUNT(*) FROM tt_troopers")
events=$(q "SELECT COUNT(*) FROM tt_events")
shifts=$(q "SELECT COUNT(*) FROM tt_event_shifts")
signups=$(q "SELECT COUNT(*) FROM tt_event_troopers")
guests=$(q "SELECT COUNT(*) FROM tt_event_guests")
log "troopers $troopers/$legacy_troopers, events $events/$legacy_events, shifts $shifts/$legacy_shifts," \
    "sign-ups $signups/$legacy_signups, guests $guests/$legacy_guests"

[ "$legacy_troopers" -gt 0 ] && [ "$legacy_events" -gt 0 ] || fail "legacy copy is empty"
[ "$troopers" -eq "$legacy_troopers" ] || fail "troopers: $troopers imported, $legacy_troopers in legacy"
[ "$events" -eq "$legacy_events" ] || fail "events: $events imported, $legacy_events in legacy"
#   shifts starting at the same minute are merged into one, so a few may be missing
[ "$shifts" -le "$legacy_shifts" ] && [ $((legacy_shifts - shifts)) -le "$SIGNUP_TOLERANCE" ] \
    || fail "shifts: $shifts imported, $legacy_shifts in legacy (tolerance $SIGNUP_TOLERANCE)"
[ "$guests" -eq "$legacy_guests" ] || fail "guests: $guests imported, $legacy_guests in legacy"
[ "$signups" -le "$legacy_signups" ] && [ $((legacy_signups - signups)) -le "$SIGNUP_TOLERANCE" ] \
    || fail "sign-ups: $signups imported, $legacy_signups in legacy (tolerance $SIGNUP_TOLERANCE)"
checkpoint check

if [ "$KEEP_LEGACY" != 1 ]
then
    #   the legacy tables are only import input; don't leave a second copy of them around
    legacy_tables=$(q "SET SESSION group_concat_max_len = 65535;
        SELECT GROUP_CONCAT(CONCAT('\`', table_name, '\`')) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name NOT LIKE 'tt\\_%'")
    [ "$legacy_tables" = NULL ] || q "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE $legacy_tables"
fi

log "switch .env to $IDLE"
(umask 077; sed "s/^DB_DATABASE=.*/DB_DATABASE=$IDLE/" .env > .env.tmp)
mv .env.tmp .env
"$PHP" artisan cache:clear --no-interaction
"$PHP" artisan queue:restart --no-interaction

#   files uploaded on staging belonged to rows that no longer exist
rm -rf storage/app/public/uploads/events storage/app/public/uploads/organizations

log "done in ${SECONDS}s: live=$IDLE"
