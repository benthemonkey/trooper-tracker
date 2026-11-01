#!/bin/bash
#
# Checks the staging cron jobs by running them the way cron does (bare environment, no
# profile), instead of waiting for the next tick. Pass --refresh to also run the nightly
# rebuild now (about a minute; staging stays up while it runs).
#
PHP=${TT_PHP:-/usr/local/php84/bin/php}
APP=${TT_APP_DIR:-$HOME/trooper-tracker/tracker-app}
fail=0

ok()  { printf '  ok    %s\n' "$*"; }
bad() { printf '  FAIL  %s\n' "$*"; fail=1; }

# what cron gives a job: no profile, a bare PATH. A missing or placeholder job isn't run.
runcron() { [[ -n $1 && $1 != *CHANGE_ME* ]] || return 1; env -i HOME="$HOME" LOGNAME="$USER" USER="$USER" PATH=/usr/bin:/bin SHELL=/bin/sh /bin/sh -c "$1"; }

waiting_jobs() { (cd "$APP" && "$PHP" artisan tinker --execute 'echo DB::table("tt_jobs")->count();' 2>/dev/null); }

cron=$(crontab -l)

# the command inside the panel's: setlock -n <lock> sh -c '<command>'
command_for() { printf '%s\n' "$cron" | grep -v '^#' | grep -- "$1" | head -1 | sed -E "s/.*sh -c '(.*)'$/\1/"; }
schedule_for() { printf '%s\n' "$cron" | grep -v '^#' | grep -- "$1" | head -1 | awk '{print $1, $2, $3, $4, $5}'; }

echo "== Cron entries"
for job in tt-staging-refresh.sh schedule:run queue:work
do
    cmd=$(command_for "$job")

    if [ -z "$cmd" ]
    then
        bad "$job: no cron job"
    elif [[ $cmd == *CHANGE_ME* ]]
    then
        bad "$job: still says CHANGE_ME"
    else
        ok "$job [$(schedule_for "$job")]: $cmd"
    fi
done

[[ $(printf '%s\n' "$cron" | grep -v '^#' | grep -c -- 'setlock.*\(tt-staging-refresh\|schedule:run\|queue:work\)') -eq 3 ]] \
    && ok "all three use locking" \
    || bad "not all three staging jobs use \"Use locking\" (setlock)"

refresh=$(command_for tt-staging-refresh.sh)
sched=$(command_for schedule:run)
queue=$(command_for queue:work)

echo "== Refresh script"
#  the panel commands spell the home directory $HOME
script=$(printf '%s\n' "$refresh" | grep -o '[^ ]*tt-staging-refresh\.sh')
script=${script//\$HOME/$HOME}
tt_php=$(printf '%s\n' "$refresh" | sed -nE 's/.*TT_PHP=([^ ]+).*/\1/p')
[ -x "$script" ] && ok "$script is executable" || bad "$script is missing or not executable"
[ -x "$tt_php" ] && ok "TT_PHP $tt_php exists" || bad "TT_PHP '$tt_php' is missing"

if [[ ${1:-} == --refresh ]]
then
    echo "   running the refresh now..."
    runcron "$refresh" && ok "refresh: $(tail -1 "$HOME/tt-staging-logs/tt-staging-refresh.log")" \
        || bad "refresh didn't run or failed; see above and ~/tt-staging-logs/tt-staging-refresh.log"
fi

echo "== Scheduler (run once, output shown)"
before=$(waiting_jobs)
if runcron "${sched% >> /dev/null 2>&1}"
then
    ok "schedule:run worked (waiting jobs: $before -> $(waiting_jobs))"
else
    bad "schedule:run didn't run (see above)"
fi

echo "== Queue (run once, output shown)"
if runcron "${queue% >> /dev/null 2>&1}"
then
    left=$(waiting_jobs)
    [ "$left" = 0 ] && ok "queue:work drained the queue (waiting jobs: 0)" \
        || bad "queue:work ran but $left job(s) are still waiting"
else
    bad "queue:work didn't run (see above)"
fi

echo
[ $fail = 0 ] && echo "All good." || echo "Something needs fixing (FAIL lines above)."
exit $fail
