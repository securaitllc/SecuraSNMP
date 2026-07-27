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

php artisan circuits:monitor &
php artisan devices:monitor &
php artisan interfaces:monitor &
php artisan health:monitor &
php artisan edgeconnect:alarms &
php artisan lldp:discover &
php artisan edgeconnect:verify &
php artisan nexthops:poll &
php artisan syslog:listen &
php artisan metrics:prune --loop &
# Processes queued jobs (discovery subnet scans run here, off the request path).
php artisan queue:work --sleep=3 --tries=1 --timeout=3600 &

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
