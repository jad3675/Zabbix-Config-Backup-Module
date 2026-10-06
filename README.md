# Config backup for Zabbix 7.4 and 8.0

Snapshots of Zabbix configuration on a schedule, copied to S3, SFTP and Git, with restore of a single object,
a selection, or a copy under a new name. Built because there is no undo for a deleted dashboard.

Version 1.4.0. Developed and tested on Zabbix 7.4.15, also running on 8.0.0, with PostgreSQL, against an S3 mock (moto), OpenSSH sftp and a bare Git
repository over SSH. Super admin only: snapshots contain users, roles and every action.

## What it backs up

| Through `get` / `create` / `update` | Through `configuration.export` / `import` |
|---|---|
| Host groups, template groups | Templates (incl. template dashboards, LLD, value maps, web scenarios) |
| Proxies, proxy groups | Hosts |
| User roles, user groups, users | Maps |
| Global macros, regular expressions, icon maps | Media types |
| Scripts, actions (all event sources), event correlation | Images |
| Discovery rules, maintenance | |
| Services, SLAs | |
| Dashboards, scheduled reports, connectors | |
| General settings, housekeeping | |

Not covered: history, trends, events, API tokens, user directories (LDAP/SAML), MFA, authentication settings.

## Install

```
cp -r configbackup /usr/share/zabbix/ui/modules/     # the frontend's modules directory
sudo sh /usr/share/zabbix/ui/modules/configbackup/contrib/install-runner.sh
```

Then:

1. Administration > General > Modules > Scan directory, enable **Config backup**. It appears as
   Administration > Config backup.
2. Config backup > Settings: enter the Zabbix URL the runner should use and an API token of a Super admin user
   (Users > API tokens). Update.
3. Add destinations and schedules.

PHP needs `curl`, `openssl` and `zlib` (all standard). Git destinations need the `git` binary. SFTP needs nothing
extra: phpseclib is bundled in `vendor/`.

### What install-runner.sh does

Safe to run again at any time; it repairs rather than assumes.

- Finds the user PHP runs the frontend as: the `user =` of the php-fpm pool (Zabbix ships `zabbix.conf`), else the
  user of running php-fpm/apache2/httpd workers, else www-data/apache/nginx. Override with `--user`.
- Creates the storage directory (`--storage`, default `/var/lib/zabbix-configbackup`) and gives it, and
  everything already in it, to that user. Clears setgid bits and tightens `.state/`. This is the fix for
  "Storage path ... is not writable by user www-data", whether the directory was made by hand as root or a CLI
  command was run as root.
- Walks up the parents and adds traverse only (`o+x`) where one blocks the web server, saying so. With the
  default path nothing needs changing.
- Warns when the storage shares a filesystem with PostgreSQL or MySQL. Snapshots are small and retention keeps
  them that way, but a dedicated volume means a runaway setting can never fill the database's disk.
- SELinux enforcing: labels the directory `httpd_sys_rw_content_t` (needs `semanage`).
- Installs the systemd timer (every 5 minutes) with the right `User=`, PHP path and `--storage`, or a cron job
  when systemd is not running or `--cron` is given.
- Runs `check` as the web server user and shows the result.

To remove: `sudo sh contrib/uninstall-runner.sh` removes the timer/cron job and keeps the snapshots.
`--purge` also deletes the storage directory (asks first unless `--yes`); copies on destinations are never
touched.

The command line refuses to run as root, because files it creates would lock the frontend out. Use
`sudo -u www-data php .../bin/zbx-config-backup.php ...` (or `--allow-root` if you really mean it).

## The runner

Schedules, copies to destinations, connection tests and pulls are done by a small runner started every 5
minutes. It exits in milliseconds when nothing is due. It runs as the web server user, so the frontend and the
runner share the storage directory.

`install-runner.sh` sets it up. To look at it:

```
systemctl list-timers zabbix-configbackup.timer
sudo -u www-data php /usr/share/zabbix/ui/modules/configbackup/bin/zbx-config-backup.php status
journalctl -u zabbix-configbackup.service -n 50
```

The unit files in `contrib/` are reference copies; the installer writes its own with your paths and user.

The runner writes a heartbeat. The snapshot list warns when it has not been seen for 15 minutes, when its API
token does not work, when a schedule failed, and when the last send to a destination failed.

Toward Zabbix the runner only reads. Restores are done in the frontend by the logged-in user, so they show up in
the audit log under a person's name.

On SELinux systems (RHEL): the runner needs to write under the storage path and, for S3/SFTP/Git, make outbound
connections. Running it from systemd as `apache` normally does not hit `httpd_t` restrictions; the frontend's
Back up now does not connect anywhere except the local API.

## Schedules

Config backup > Schedules. Each schedule has:

- when: daily at HH:MM, weekly on a day at HH:MM, or every 1/2/3/4/6/8/12 hours starting at HH:MM, in the time
  zone chosen in Settings (UTC until one is chosen). The runner, the frontend and the command line all use that
  zone explicitly, whatever php.ini or a user's Zabbix profile say.
- which object types (all, or a subset: an hourly schedule of just dashboards, actions and users is cheap)
- which destinations to copy to
- how many of its own snapshots to keep locally (and/or for how many days)

A slot missed while the runner was down runs once when it is back, not once per missed slot. A failed run
retries after 5, 10, 20 minutes, then hourly. A failed copy to a destination does not fail the run: it is queued
and retried up to 5 times.

Retention is counted per schedule, locally and on every destination. A frequent light schedule never pushes the
nightly full snapshots out. Manual snapshots have their own retention (Settings). Pinned snapshots and the newest
one are never removed.

### Why didn't my schedule run?

The runner writes every decision to `<storage>/.state/runner.log`, also with `--quiet`. Read it on the Schedules
page (Runner log), or:

```
sudo -u www-data php /usr/share/zabbix/ui/modules/configbackup/bin/zbx-config-backup.php log 30
sudo -u www-data php /usr/share/zabbix/ui/modules/configbackup/bin/zbx-config-backup.php status
systemctl list-timers zabbix-configbackup.timer
```

A schedule is armed the moment it is saved (or the time zone changes): every slot after that runs, even if
the runner's next pass comes a few minutes later. A slot that was already past when you saved does not run;
use Run now for that.

## Notify through Zabbix

Zabbix is a monitoring system; it should tell you when its own backups stop. Settings > Notify through Zabbix >
**Create host and turn on** creates (as you, so it is in the audit log):

- the template **Config backup by runner** (group Templates/Applications), and
- a host **Config backup** in **Zabbix servers** (both names editable), with no interfaces, linked to it.

From then on the runner pushes its state to that host on every pass with `history.push`: trapper items, no
port 10051, the API token it already has. Schedules and destinations are discovered (trapper LLD), so adding
one in the module adds its items and triggers. Problems go through your normal actions and media:

| Severity | Problem | Clears when |
|---|---|---|
| Average | no word from the runner for 20m (`{$CONFIGBACKUP.RUNNER.NODATA}`) | the runner reports again |
| Average | the runner cannot use the Zabbix API | the API check passes |
| Average | schedule "X" failed (the failure message is the operational data) | the next successful run |
| High | schedule "X" is overdue: no success by the slot after its last success, plus an hour | a successful run |
| Warning | schedule "X" could not read everything (partial snapshot) | a complete run |
| Warning | sending to "Y" failed | the next successful send |

The nodata trigger is the one that catches everything the runner cannot report itself: a stopped timer, a dead
server, an API it cannot reach. Requires Zabbix 7.0 or later (`history.push`).

`history.push` checks the address the API call comes from against each trapper item's Allowed hosts. Left
empty, Zabbix accepts only loopback, so a runner calling `http://<server address>/zabbix` is refused ("Client IP
is not in item's allowed hosts list"). The template's items use `{$CONFIGBACKUP.ALLOWED.HOSTS}`, and setup puts
the right addresses on the host: loopback, the address of the Zabbix URL in Settings, and the frontend's own.
Behind a reverse proxy or NAT, add the address Zabbix actually sees to that host macro. `zbx-config-backup.php
report` shows each value's result.

Just after setup, the first pass creates the discovered items; Zabbix accepts values for them once its
configuration cache has caught up (a minute or so). Turn off stops reporting and leaves the host: delete or
disable it, or its nodata problem will fire. Update host and template re-applies the template after upgrading
the module.

## Destinations

Config backup > Destinations. Every snapshot is always kept locally too: that is what you browse and restore
from. Saving a destination queues a connection test; the result shows in the list within 5 minutes.

### S3

AWS S3 and anything S3-compatible (MinIO, Wasabi, Backblaze B2, Ceph RGW...). Signature V4 is implemented
directly over curl; it was checked byte for byte against botocore for virtual-host, path-style and custom-path
endpoints.

- Endpoint empty for AWS; path-style on for most self-hosted S3.
- Use a prefix per Zabbix instance.
- Give the access key only `ListBucket` on the prefix and `Put/Get/DeleteObject` under it
  (`contrib/s3-policy.example.json`).
- Turn on bucket versioning or Object Lock. Then a compromised Zabbix server cannot destroy its own history.
  With Object Lock, retention deletes are refused by the bucket; that is the point.

### SFTP

- Private key (OpenSSH or PEM, optional passphrase) or password.
- Host key: put the `SHA256:...` fingerprint from `ssh-keygen -lf` in the form to pin it. Left empty, the first key
  seen is trusted and then required (like `ssh -o StrictHostKeyChecking=accept-new`); the destination page shows
  the fingerprint it recorded.
- Files are uploaded as `.name.part` and renamed into place, so a broken transfer never looks like a snapshot.

### Git

Not a backup target but a change history. Each snapshot is written as one file per object, IDs and runtime
state removed and keys sorted, and committed only when something changed:

```
2026-10-02 17:41: 1 changed, 1 deleted, 1 added

Changed action "CB Autoreg"
Deleted dashboard "CB Dashboard copy"
Added global macro "{$NEW.ONE}"

Snapshot 20261002-174122-41c8e6 (after edits), Zabbix 7.4.15
```

Your Git host then shows who changed what, as ordinary diffs.

- SSH deploy key (paste the `ssh-keyscan` line for the host to pin it) or HTTPS with a token.
- Users, user groups and media types are left out by default.
- Use a private repository. Git copies are not encrypted, because encrypted blobs make useless diffs.
- Git keeps history, not restorable snapshots: restore from local, S3 or SFTP.

### Encryption of off-box copies

Snapshots are not harmless: they hold e-mail addresses, SNMP communities and v3 passphrases from discovery rules,
and webhook parameters (Slack and Teams webhook URLs carry their secret in the URL). Set a public key on S3 and
SFTP destinations and copies are encrypted before they leave the server.

```
# on your own machine, not the Zabbix server
php zbx-config-backup.php keygen configbackup-private.pem configbackup-public.pem
```

Paste the public key into the destination. Keep the private key somewhere else (password manager, vault, offline).
Without it nobody can read the copies: not the bucket admin, not whoever finds the SFTP share, and not you.

Format: RSA-OAEP wraps a random AES-256 key; the tar is encrypted with AES-256-GCM in 1 MiB chunks with an
authenticated end marker, so truncated or altered files are refused, not half-restored.

## Getting snapshots back

Sending a snapshot copies it; the local one stays until local retention removes it. Destinations usually keep
more than the server does (say 7 locally, 90 on S3), so the Snapshots page shows one history: local snapshots
and copies that only exist on destinations, newest first. Remote-only rows are marked "remote only" and show
where the copies are (a lock means encrypted), with label and object count when this server sent them.
"Hide remote-only" switches to local snapshots only.

**Pull and open** fetches the copy right away from the web server, unpacks it into local storage and opens it,
ready to browse and restore like any other snapshot. If the web server cannot reach the destination (SELinux
blocking outbound connections from php-fpm, a proxy only the runner uses), the pull is handed to the runner and
appears within 5 minutes.

Pulled snapshots are **pinned** on arrival. An old snapshot brought back would otherwise be first in line for
retention on the next run. The snapshot page says where it came from and has an "Unpin, I am done" button.

Which copies exist comes from each destination's last listing. The runner refreshes it every 6 hours and after
every send; Destinations > Snapshots there > Refresh list forces it.

Encrypted copies can be pulled from the frontend only if the destination has a private key path on this
server. Without one (the safer choice), the row says so and the command line does it:

```
zbx-config-backup.php remote "S3 offsite"
zbx-config-backup.php pull "S3 offsite" 20261002-174349-dcf90b.nightly.tar.cbk --key=/path/configbackup-private.pem
```

Rebuilding a lost Zabbix server: install Zabbix and this module, set the storage path, API token and the same
destination. Its copies show up as remote-only once the runner lists it; pull one and restore from it.

Without any Zabbix at all, to read a copy:

```
zbx-config-backup.php decrypt 20261002-174349-dcf90b.nightly.tar.cbk --key=configbackup-private.pem
tar xf 20261002-174349-dcf90b.nightly.tar     # one gzipped JSON-lines file per object type
```

Template, host, map and media type objects can also be downloaded from the object page as ordinary Zabbix export
files and imported anywhere through Data collection > Import.

## Restore

| Mode | Applies to | What it does |
|---|---|---|
| Recreate | all | Creates the object again with a new ID. With a new name, creates a copy next to the original. |
| Overwrite current | all | Replaces the live configuration with the snapshot version. If the object was recreated by hand under a different ID, that one is overwritten. |
| Restore missing parts | templates, hosts, maps, media types, images | Creates the object if gone, plus deleted items, triggers, graphs, LLD rules, web scenarios, value maps and template links. Touches nothing that exists. |
| Exact rollback | templates, hosts | Overwrite, and also deletes parts added since the snapshot. History of deleted items is lost. |
| Import as a copy | templates, hosts, maps... | Imports under a new name with fresh UUIDs; `/Old name/key` in expressions and graph/dashboard references are rewritten. |

### Single parts of a template or host

The object page of a template or host lists every item, trigger, graph, discovery rule, web scenario, value
map, template dashboard and macro in the snapshot, each marked Missing, Changed or Unchanged against the live
object. Tick the ones you want and restore them, either only if missing or overwriting changed ones.

- The template or host itself is not touched: no field, tag, link or other macro changes.
- What a part cannot exist without comes back with it, but only if it is missing too: the items a trigger,
  graph or dashboard uses, a dependent item's master, an item's value map, triggers it depends on.
- Restoring a deleted trigger also relinks other triggers that depended on it (Zabbix drops those links on
  delete).
- Deleting an item, graph or discovery rule makes Zabbix strip the widgets that showed it from template
  dashboards. The result says which dashboards those were; restore them too with overwrite.
- Macros go through `usermacro.*` (an import would replace the whole macro list), including the 7.4+ template
  wizard settings. Secret macro values are never exported, so they come back empty.
- Items inside a discovery rule are restored by restoring the rule.

Bulk restore from the snapshot page runs in dependency order (groups, proxies, templates, hosts, roles, user
groups, users, ... actions, dashboards, reports) with one of: recreate if missing; recreate if missing plus missing
parts for templates/hosts/maps; or overwrite.

Typical recoveries:

- **Deleted dashboard**: open the snapshot, Show everything that is gone, tick it, Restore.
- **Deleted template**: tick the template and the hosts that used it, mode "Recreate if missing; templates/hosts/maps
  also get missing parts back". The template comes back, then the hosts are relinked.
- **Someone "tidied up" an action**: open it, read the diff, Overwrite current.
- **Want last week's version next to the current one**: Recreate (or Import as a copy) with a new name.

### How references survive a recreate

A recreated object gets a new ID. Before anything is restored, each reference it holds is resolved:

1. recreated from this snapshot earlier (`remap.json` in the snapshot) and still there: the new ID
2. the old ID still exists: keep it
3. something with the same name exists now: that
4. otherwise the old ID, and the API says what is missing

Action conditions, dashboard widget fields, user group rights and tag filters are remapped too. Optional links to
things that are still gone (dashboard sharing, group membership, service parents/children, report recipients) are
left out with a note, so the object itself can come back.

### Validation self-healing

Zabbix 7.4 rejects fields that `get` returns but that do not apply to the particular object (`esc_period` on a
discovery action, `value2` on a non-tag condition). Restore drops exactly the field the API names in an
"unexpected parameter" or "value must be empty" error and retries. Nothing with meaning is dropped.

## Things that cannot come back

Zabbix never returns these through the API, so no backup has them:

- user passwords: a recreated internal user gets a random temporary password, shown once in the result
- secret text macro values: restored empty
- proxy PSKs: a PSK proxy is restored unencrypted
- connector tokens and passwords, SSH/Telnet script passwords and key passphrases
- media type passwords (not exported by Zabbix)

Also: a recreated host gets new item IDs, so dashboard widgets pointing at specific items of that host need
re-picking (widgets pointing at hosts and host groups are remapped). History and trends are not configuration.

## Security model, plainly

- Stored secrets (API token, S3 secret key, SFTP password/key, Git key/token) are sealed to the runner's key pair
  and never sent back to the browser. The frontend opens them server-side only to pull a snapshot (Pull and open). This protects them from anyone who sees the settings page, a browser cache,
  or a copy of `settings.json` on its own.
- It does **not** protect them from a shell as the web server user: the runner's private key sits next to them in
  `<storage>/.state/`, because the runner has to read them unattended. Treat that directory like `zabbix.conf.php`.
- The `.state` directory is never shipped to any destination.
- Off-box copies are as safe as your encryption key handling. A destination without a public key gets plain tars.

## Command line

```
zbx-config-backup.php run-due                       # what the timer runs
zbx-config-backup.php backup [--label=TEXT] [--to=DEST,...]
zbx-config-backup.php list | prune | status | check
zbx-config-backup.php restore SNAPSHOT TYPE ID [--mode=create|overwrite|missing|exact] [--name=NEW]
zbx-config-backup.php test DEST | ship SNAPSHOT DEST | remote DEST | pull DEST NAME [--key=FILE]
zbx-config-backup.php keygen PRIVATE.pem PUBLIC.pem
zbx-config-backup.php decrypt FILE.tar.cbk --key=PRIVATE.pem [--out=FILE.tar]
```

`--storage=DIR` (default `/var/lib/zabbix-configbackup`, or `$CONFIGBACKUP_STORAGE`), `--quiet`. DEST is a
destination name or ID. Exit codes: 0 ok, 1 partly failed, 2 failed. Run as the web server user.

## Storage layout

```
<storage>/
  20261002-174349-dcf90b/          one snapshot
    meta.json                      created, source, schedule, user, label, note, pinned, counts, errors, uploads
    <type>.index.json              id, name for listing
    <type>.jsonl.gz                one object per line
    remap.json                     old id -> new id for objects recreated from this snapshot
  .state/                          never shipped
    settings.json                  settings, secrets sealed
    runner.key, runner.pub         key pair for the sealing
    runner.json                    heartbeat
    schedules.json, destinations.json
    queue/, done/                  requests from the frontend and their results
    git/<destination>/             working copies for Git destinations
```

On S3 and SFTP a snapshot is one file, `<snapshot>.<schedule-id or manual>.tar`, or `.tar.cbk` when encrypted.
Remote retention only ever touches files named like that.

## Known limits

- **Back up now** on a large install can outlast nginx's default 60s FastCGI timeout. The snapshot still finishes,
  the page just will not show the result. Use a schedule (or Run now) for big ones.
- Templates and hosts are exported one at a time so each can be restored alone. Several thousand hosts is
  minutes.
- Discovered hosts and host groups (LLD-created) are skipped; their prototypes are in the templates.
- Tested against moto, OpenSSH and a local Git repository. Real AWS, appliances with odd SFTP servers and hosted
  Git will find environmental problems (proxies, SELinux, permissions) that a sandbox cannot.

## Bundled code

- phpseclib 3.0.47 (MIT), `vendor/phpseclib`
- paragonie/constant_time_encoding 3.0.0 (MIT), `vendor/paragonie`
