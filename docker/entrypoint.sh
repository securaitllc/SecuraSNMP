#!/bin/sh
set -e

# Honor an explicit command (e.g. `docker run <image> php artisan ...`) so the
# image can run one-off tasks instead of always booting the full application.
if [ "$#" -gt 0 ]; then
  exec "$@"
fi

# Fail fast with a clear message on a missing/garbage APP_KEY, instead of every
# request returning a blank HTTP 500 from the encrypter.
case "$APP_KEY" in
  "" )
    echo "FATAL: APP_KEY is not set. Generate one with:  echo \"base64:\$(openssl rand -base64 32)\"  and set it as APP_KEY." >&2
    exit 1 ;;
  *" "* )
    echo "FATAL: APP_KEY is invalid (it contains spaces — likely pasted instruction text). Set it to the output of:  echo \"base64:\$(openssl rand -base64 32)\"" >&2
    exit 1 ;;
esac

php artisan package:discover --ansi
php artisan config:cache
php artisan migrate --force
# Creates the initial admin user on first boot (only when no users exist).
php artisan app:ensure-admin

# --- Poller supervision -------------------------------------------------------
# Each poller is its own process (independent — one crashing never touches the
# others), but nothing used to restart a poller that died or hung. circuits:monitor
# stalled unnoticed for ~20h twice (2026-07-26, 2026-07-27): a recovered circuit
# could never auto-clear because nothing re-pinged it, while the sibling loops kept
# running and hid the outage.
#
# supervise() runs one poller in its own background subshell and:
#   * restarts it if the process EXITS (PHP fatal, OOM-kill, crash);
#   * for heartbeat pollers ($hb=1), kills+restarts it when its heartbeat file goes
#     stale past max(3*interval, 180s) — the HANG case try/catch cannot cover.
# Heartbeat files are written by RunsPollLoop; /api/health/pollers reports them.
POLLER_DIR=/var/www/html/storage/app/pollers
mkdir -p "$POLLER_DIR"

supervise() {
  label="$1"; hb="$2"; shift 2
  (
    set +e   # a failing kill/cut must never end the supervisor subshell itself
    while true; do
      php artisan "$@" &
      pid=$!
      started=$(date +%s)
      while kill -0 "$pid" 2>/dev/null; do
        sleep 30
        [ "$hb" = "1" ] || continue
        now=$(date +%s)
        beat="$POLLER_DIR/$label.beat"
        if [ ! -f "$beat" ]; then
          # No beat yet: allow 300s of start-up grace before judging it dead.
          if [ $((now - started)) -gt 300 ]; then
            echo "[supervisor] $label: no heartbeat 300s after start — restarting" >&2
            kill "$pid" 2>/dev/null; sleep 5; kill -9 "$pid" 2>/dev/null
            break
          fi
          continue
        fi
        ts=$(cut -d' ' -f1 "$beat" 2>/dev/null); interval=$(cut -d' ' -f2 "$beat" 2>/dev/null)
        [ -n "$ts" ] || ts=0
        [ -n "$interval" ] || interval=60
        threshold=$((interval * 3)); [ "$threshold" -lt 180 ] && threshold=180
        age=$((now - ts))
        if [ "$age" -gt "$threshold" ]; then
          echo "[supervisor] $label: heartbeat stale ${age}s (>${threshold}s) — killing pid $pid to restart" >&2
          kill "$pid" 2>/dev/null; sleep 5; kill -9 "$pid" 2>/dev/null
          break
        fi
      done
      wait "$pid" 2>/dev/null
      echo "[supervisor] $label exited — restarting in 5s" >&2
      sleep 5
    done
  ) &
}

#         label        heartbeat?  artisan command
supervise circuits     1  circuits:monitor
supervise devices      1  devices:monitor
supervise interfaces   1  interfaces:monitor
supervise health       1  health:monitor
supervise ec-alarms    1  edgeconnect:alarms
supervise lldp         1  lldp:discover
supervise tunnels-ssh  1  edgeconnect:verify
supervise nexthops     1  nexthops:poll
supervise prune        1  metrics:prune --loop
# No poll-loop heartbeat (UDP listener / Laravel worker): restart-on-death only.
supervise syslog       0  syslog:listen
# Processes queued jobs (discovery subnet scans run here, off the request path).
supervise queue        0  queue:work --sleep=3 --tries=1 --timeout=3600

# Generate the trap-daemon config with the configured community so it can be
# changed per deployment instead of being hardcoded. Only traps bearing this
# community are accepted (though UDP source addresses are still spoofable —
# keep udp/162 firewalled to the device management network).
cat > /etc/snmp/snmptrapd.conf <<CONF
authCommunity log,execute ${TRAP_COMMUNITY:-public}
traphandle default /var/www/html/docker/trap-handler.sh
disableAuthorization no
CONF
snmptrapd -f -Lo -c /etc/snmp/snmptrapd.conf -m '' 162 &
exec php artisan serve --host=0.0.0.0 --port=8000
