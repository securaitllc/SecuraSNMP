#!/bin/sh
set -e

# Honor an explicit command (e.g. `docker run <image> php artisan ...`) so the
# image can run one-off tasks instead of always booting the full application.
if [ "$#" -gt 0 ]; then
  exec "$@"
fi

# Role of THIS container:
#   all     — web + pollers in one container (default; the classic single-container deploy)
#   web     — nginx + php-fpm only (serves the app + runs migrations)
#   pollers — the monitoring loops + snmptrapd + syslog + queue worker only
# Splitting web and pollers into two containers keeps a heavy SNMP/SSH sweep from
# ever competing with web requests for CPU. Both roles run the SAME image; the
# poller heartbeats live on a volume the web container also mounts so
# /api/health/pollers still sees them.
APP_ROLE="${APP_ROLE:-all}"

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

# Migrations + first-boot admin belong to the WEB role (or the all-in-one), run
# once. The pollers AND flows containers must NOT also migrate — compose gates them
# behind the web container's healthcheck so the schema is ready before the loops start.
if [ "$APP_ROLE" = "web" ] || [ "$APP_ROLE" = "all" ]; then
  php artisan migrate --force
  php artisan app:ensure-admin
fi

# --- Poller supervision (pollers role, or the all-in-one) ---------------------
if [ "$APP_ROLE" != "web" ]; then
  # Each poller is its own process (independent — one crashing never touches the
  # others). supervise() runs one in a background subshell and:
  #   * restarts it if the process EXITS (PHP fatal, OOM-kill, crash);
  #   * for heartbeat pollers ($hb=1), kills+restarts it when its heartbeat file
  #     goes stale past max(3*interval, 180s) — the HANG case try/catch can't cover.
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

  # Flow subsystem (flows role or all-in-one) — a DEDICATED container so a high-volume
  # NetFlow/sFlow burst can never compete with or starve the SNMP alarm pollers (the
  # critical raise/clear path). goflow2 decodes into the shared volume; these ingest it.
  if [ "$APP_ROLE" = "flows" ] || [ "$APP_ROLE" = "all" ]; then
    # goflow2 runs NON-root and shares the flow-spool volume; the volume is root-owned,
    # so make the spool dir writable here (this container is root) instead of running
    # goflow2 as root. goflow2 may restart a couple times until this runs on first boot.
    mkdir -p /flows && chmod 0777 /flows 2>/dev/null || true
    supervise flows-ingest  1  flows:ingest
    supervise flow-rollup   1  flows:rollup
  fi

  # SNMP/SSH monitoring loops — pollers role (or all-in-one), NOT the flows container.
  if [ "$APP_ROLE" != "flows" ]; then
  #         label        heartbeat?  artisan command
  supervise circuits     1  circuits:monitor
  supervise devices      1  devices:monitor
  supervise interfaces   1  interfaces:monitor
  supervise health       1  health:monitor
  supervise ec-alarms    1  edgeconnect:alarms
  supervise lldp         1  lldp:discover
  supervise tunnels-ssh  1  edgeconnect:verify
  supervise nexthops     1  nexthops:poll
  supervise macs         1  mac:monitor
  supervise prune        1  metrics:prune --loop
  supervise vuln         1  vuln:refresh
  supervise anomaly      1  anomaly:monitor
  supervise syslog       0  syslog:listen
  supervise queue        0  queue:work --sleep=3 --tries=1 --timeout=3600

  # SNMP trap daemon: only traps bearing the configured community are accepted
  # (UDP source addresses are still spoofable — keep udp/162 firewalled to the
  # device management network).
  cat > /etc/snmp/snmptrapd.conf <<CONF
authCommunity log,execute ${TRAP_COMMUNITY:-public}
traphandle default /var/www/html/docker/trap-handler.sh
disableAuthorization no
CONF
  snmptrapd -f -Lo -c /etc/snmp/snmptrapd.conf -m '' 162 &
  fi
fi

# --- Foreground process (keeps the container alive) ---------------------------
if [ "$APP_ROLE" = "pollers" ] || [ "$APP_ROLE" = "flows" ]; then
  # Supervisors run in the background; hold PID 1 open.
  echo "[entrypoint] role=${APP_ROLE} — background loops running"
  exec tail -f /dev/null
else
  # web / all: concurrent php-fpm worker pool + nginx (replaces single-threaded
  # `php artisan serve`, so concurrent requests no longer serialize).
  echo "[entrypoint] role=${APP_ROLE} — starting php-fpm + nginx"
  php-fpm --daemonize --allow-to-run-as-root
  exec nginx -g 'daemon off;'
fi
