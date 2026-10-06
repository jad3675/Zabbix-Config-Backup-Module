#!/bin/sh
# Installs the Config backup runner and makes the storage directory usable by the frontend.
# Safe to run again: it repairs ownership and permissions and replaces the timer.
#
#   sh contrib/install-runner.sh [--storage DIR] [--user USER] [--php PATH] [--cron]
#
#   --storage DIR   default /var/lib/zabbix/configbackup. If you change it, set the same path in
#                   Config backup > Settings.
#   --user USER     the user PHP runs as for the Zabbix frontend. Detected from the php-fpm pool or the
#                   running web server when omitted (www-data, apache or nginx).
#   --php PATH      PHP CLI binary, default: the one in PATH.
#   --cron          use /etc/cron.d instead of a systemd timer.

set -eu

step() { echo; echo "==> $*"; }
trap 'rc=$?; [ $rc -eq 0 ] || echo "FAILED at the step above (exit $rc). Nothing after it ran." >&2' EXIT

MODULE_DIR=$(cd "$(dirname "$0")/.." && pwd)
STORAGE=/var/lib/zabbix/configbackup
WEB_USER=
PHP_BIN=
USE_CRON=0

while [ $# -gt 0 ]; do
	case "$1" in
		--storage) STORAGE=${2%/}; shift 2 ;;
		--user) WEB_USER=$2; shift 2 ;;
		--php) PHP_BIN=$2; shift 2 ;;
		--cron) USE_CRON=1; shift ;;
		-h|--help) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) echo "unknown option: $1 (try --help)" >&2; exit 2 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run this as root (sudo sh $0)." >&2; exit 1; }

case "$STORAGE" in
	/*) ;;
	*) echo "--storage must be an absolute path." >&2; exit 2 ;;
esac

step "finding the web server user"

if [ -z "$WEB_USER" ]; then
	# 1. The php-fpm pool Zabbix ships (zabbix.conf), then any pool.
	for pool in /etc/php/*/fpm/pool.d/zabbix.conf /etc/php-fpm.d/zabbix.conf \
			/etc/php/*/fpm/pool.d/*.conf /etc/php-fpm.d/*.conf; do
		[ -f "$pool" ] || continue
		WEB_USER=$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*\([^[:space:];]*\).*/\1/p' "$pool" | head -n 1)
		[ -n "$WEB_USER" ] && { echo "from $pool"; break; }
	done
fi

if [ -z "$WEB_USER" ]; then
	# 2. Whoever runs the worker processes (mod_php under Apache, or a pool we did not find).
	WEB_USER=$(ps -eo user=,comm= 2>/dev/null \
		| awk '$2 ~ /^(php-fpm|php-fpm[0-9.]*|apache2|httpd)$/ && $1 != "root" {print $1; exit}')
	[ -n "$WEB_USER" ] && echo "from the running web server processes"
fi

if [ -z "$WEB_USER" ]; then
	# 3. The usual names.
	for u in www-data apache nginx; do
		if id "$u" >/dev/null 2>&1; then WEB_USER=$u; echo "guessed: no php-fpm pool or web server found"; break; fi
	done
fi

[ -n "$WEB_USER" ] && id "$WEB_USER" >/dev/null 2>&1 \
	|| { echo "Cannot tell which user runs the Zabbix frontend. Use --user." >&2; exit 1; }

WEB_GROUP=$(id -gn "$WEB_USER")
echo "user:     $WEB_USER ($WEB_GROUP)"
echo "module:   $MODULE_DIR"
echo "storage:  $STORAGE"

if [ -z "$PHP_BIN" ]; then
	PHP_BIN=$(command -v php || true)
fi
[ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ] || { echo "No PHP CLI found. Install php-cli or use --php." >&2; exit 1; }

for ext in curl openssl zlib json; do
	"$PHP_BIN" -m | grep -qix "$ext" || { echo "PHP CLI is missing the $ext extension." >&2; exit 1; }
done
echo "php:      $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

STORAGE_ARG=
[ "$STORAGE" = /var/lib/zabbix/configbackup ] || STORAGE_ARG=" --storage=$STORAGE"

step "creating the storage directory"

mkdir -p "$STORAGE"
# Anything created by hand or by running the CLI as root becomes the web user's.
chown -R "$WEB_USER:$WEB_GROUP" "$STORAGE"
# Numeric chmod does not clear setgid on directories with GNU chmod; do it by name.
find "$STORAGE" -type d -exec chmod u=rwx,g=rx,o=,g-s {} +
find "$STORAGE" -type f -exec chmod u=rw,g=r,o= {} +
if [ -d "$STORAGE/.state" ]; then
	find "$STORAGE/.state" -type d -exec chmod 0700 {} +
	find "$STORAGE/.state" -type f -exec chmod 0600 {} +
fi
ls -ld "$STORAGE" | sed 's/^/  /'

step "checking every parent directory lets $WEB_USER through"

# A 0750 /var/lib/zabbix (home of the zabbix user) blocks the web server even when the directory
# itself is right. Grant traverse only (o+x): nothing becomes listable or readable.
dir=$(dirname "$STORAGE")
while [ "$dir" != / ]; do
	if ! runuser -u "$WEB_USER" -- test -x "$dir"; then
		chmod o+x "$dir"
		echo "  $dir: added o+x so $WEB_USER can reach the storage directory (no read or list rights)"
	fi
	dir=$(dirname "$dir")
done

runuser -u "$WEB_USER" -- test -w "$STORAGE" || {
	echo "$WEB_USER still cannot write $STORAGE:" >&2
	namei -l "$STORAGE" >&2 || true
	echo "Is it on a read-only, root-squashed or noexec mount? Try --storage somewhere else." >&2
	exit 1
}
echo "  ok: $WEB_USER can write $STORAGE"

step "SELinux"

if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" != Disabled ]; then
	if command -v semanage >/dev/null 2>&1; then
		semanage fcontext -a -t httpd_sys_rw_content_t "$STORAGE(/.*)?" 2>/dev/null \
			|| semanage fcontext -m -t httpd_sys_rw_content_t "$STORAGE(/.*)?"
		restorecon -R "$STORAGE"
		echo "  labelled $STORAGE httpd_sys_rw_content_t so php-fpm/httpd may write it"
	else
		echo "  SELinux is $(getenforce) but semanage is missing (dnf install policycoreutils-python-utils)." >&2
		echo "  Without it the frontend cannot write $STORAGE. Run this script again after installing it." >&2
		exit 1
	fi
else
	echo "  not enabled, nothing to do"
fi

step "removing the 1.0 cron job"

if [ -f /etc/cron.d/zabbix-configbackup ] && grep -q ' backup' /etc/cron.d/zabbix-configbackup; then
	rm -f /etc/cron.d/zabbix-configbackup
	echo "  removed /etc/cron.d/zabbix-configbackup (ran 'backup' directly; schedules replace it)"
else
	echo "  none found"
fi

step "installing the runner"

CMD="$PHP_BIN $MODULE_DIR/bin/zbx-config-backup.php run-due --quiet$STORAGE_ARG"

if [ "$USE_CRON" -eq 0 ] && command -v systemctl >/dev/null 2>&1 \
		&& systemctl is-system-running 2>/dev/null | grep -qvx offline; then
	cat > /etc/systemd/system/zabbix-configbackup.service <<UNIT
[Unit]
Description=Zabbix config backup runner (schedules and queued requests)
After=network-online.target

[Service]
Type=oneshot
User=$WEB_USER
Group=$WEB_GROUP
UMask=0027
ExecStart=$CMD
Nice=10
UNIT

	cat > /etc/systemd/system/zabbix-configbackup.timer <<UNIT
[Unit]
Description=Zabbix config backup runner, every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s

[Install]
WantedBy=timers.target
UNIT

	rm -f /etc/cron.d/zabbix-configbackup
	systemctl daemon-reload
	systemctl enable --now zabbix-configbackup.timer >/dev/null
	systemctl start zabbix-configbackup.service || true
	echo "  systemd timer installed and started"
	systemctl list-timers zabbix-configbackup.timer --no-pager 2>/dev/null | sed -n '1,2p' | sed 's/^/  /'
else
	[ "$USE_CRON" -eq 1 ] || echo "  systemd is not running here; using cron instead"
	cat > /etc/cron.d/zabbix-configbackup <<CRON
# Config backup runner: schedules and requests queued from the Zabbix frontend.
SHELL=/bin/sh
*/5 * * * * $WEB_USER umask 027; $CMD
CRON
	chmod 0644 /etc/cron.d/zabbix-configbackup
	echo "  /etc/cron.d/zabbix-configbackup installed"
	runuser -u "$WEB_USER" -- sh -c "umask 027; $CMD" || true
fi

step "checking from the runner's side"

runuser -u "$WEB_USER" -- "$PHP_BIN" "$MODULE_DIR/bin/zbx-config-backup.php" check$STORAGE_ARG || true

echo
echo "Done. If the api line above is not OK yet: Config backup > Settings, enter the Zabbix URL and an"
echo "API token of a Super admin user. Then add destinations and a schedule."
[ -z "$STORAGE_ARG" ] || echo "You used a non-default storage path: set $STORAGE in Settings as well."
echo "Status at any time:  sudo -u $WEB_USER $PHP_BIN $MODULE_DIR/bin/zbx-config-backup.php status$STORAGE_ARG"
