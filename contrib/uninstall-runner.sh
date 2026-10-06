#!/bin/sh
# Removes the Config backup runner (timer, service or cron job). Snapshots and settings stay unless
# --purge is given. The module itself stays: disable it in Zabbix and delete its directory for that.
#
#   sh contrib/uninstall-runner.sh [--purge] [--yes] [--storage DIR]
#
#   --purge   also delete the storage directory: every local snapshot, settings, sealed credentials.
#             Copies on S3/SFTP/Git are not touched.
#   --yes     do not ask before --purge.
#   --storage DIR   default /var/lib/zabbix-configbackup (/var/lib/zabbix/configbackup before 1.4.0).

set -eu

STORAGE=/var/lib/zabbix-configbackup
PURGE=0
YES=0

while [ $# -gt 0 ]; do
	case "$1" in
		--purge) PURGE=1; shift ;;
		--yes) YES=1; shift ;;
		--storage) STORAGE=${2%/}; shift 2 ;;
		-h|--help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run this as root." >&2; exit 1; }

if command -v systemctl >/dev/null 2>&1; then
	systemctl disable --now zabbix-configbackup.timer 2>/dev/null || true
	systemctl stop zabbix-configbackup.service 2>/dev/null || true
	systemctl reset-failed zabbix-configbackup.service 2>/dev/null || true
fi

rm -f /etc/systemd/system/zabbix-configbackup.service /etc/systemd/system/zabbix-configbackup.timer \
	/etc/cron.d/zabbix-configbackup
command -v systemctl >/dev/null 2>&1 && systemctl daemon-reload 2>/dev/null || true
echo "removed the runner (timer, service, cron job)"

if [ "$PURGE" -eq 0 ]; then
	echo "kept $STORAGE (snapshots, settings, credentials). Add --purge to delete it too."
	echo "Run contrib/install-runner.sh to set the runner up again."
	exit 0
fi

case "$STORAGE" in
	/|/var|/var/lib|/var/lib/zabbix|/usr|/usr/*|/etc|/etc/*|/home|/root)
		echo "Refusing to delete $STORAGE." >&2; exit 1 ;;
esac

if [ ! -d "$STORAGE" ]; then
	echo "$STORAGE does not exist."
	[ -d /var/lib/zabbix/configbackup ] && echo "Found the pre-1.4.0 location /var/lib/zabbix/configbackup: add --storage /var/lib/zabbix/configbackup to purge that."
	exit 0
fi

count=$(find "$STORAGE" -mindepth 2 -maxdepth 2 -name meta.json | wc -l)

if [ "$YES" -eq 0 ]; then
	printf 'Delete %s with %s local snapshot(s), settings and credentials? Type yes: ' "$STORAGE" "$count"
	read -r answer
	[ "$answer" = yes ] || { echo "Not deleted."; exit 1; }
fi

rm -rf "$STORAGE"

if command -v semanage >/dev/null 2>&1; then
	semanage fcontext -d "$STORAGE(/.*)?" 2>/dev/null || true
fi

echo "deleted $STORAGE ($count snapshot(s)). Copies on destinations were not touched."
