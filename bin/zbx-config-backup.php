#!/usr/bin/env php
<?php declare(strict_types = 0);
/*
 * Config backup for Zabbix: background runner and command line.
 *
 * Runner (systemd timer or cron, every 5 minutes, as the web server user):
 *   run-due                       queued requests from the frontend, then due schedules
 *
 * Snapshots:
 *   backup [--label=TEXT] [--to=DEST,...]   take one now (types and retention from Settings)
 *   list                          local snapshots
 *   prune                         apply local retention
 *   restore SNAPSHOT TYPE ID [--mode=create|overwrite|missing|exact] [--name=NEW]
 *
 * Destinations (DEST = name or ID from Settings > Destinations):
 *   test DEST                     connect, write, list, delete a probe file
 *   ship SNAPSHOT DEST            send a local snapshot
 *   remote DEST                   list snapshots stored there
 *   pull DEST NAME [--key=FILE]   copy one back into local storage (FILE = private key if encrypted)
 *
 * Disaster recovery, no Zabbix or settings needed:
 *   keygen PRIVATE.pem PUBLIC.pem create an RSA key pair for encrypting off-box snapshots
 *   decrypt FILE.tar.cbk --key=PRIVATE.pem [--out=FILE.tar]
 *
 *   status                        runner heartbeat, schedules, destinations, queue
 *   log [N]                       last N lines (default 50) of the runner's log
 *   report                        push the state to the Zabbix monitoring host now, showing each value's result
 *   check                         storage, keys, API token, git, vendored SFTP library
 *   use-storage                   point the frontend (module config in Zabbix) at --storage; used after a move
 *
 * Options:
 *   --storage=DIR   default /var/lib/zabbix-configbackup (or $CONFIGBACKUP_STORAGE)
 *   --quiet         only errors
 *   --allow-root    run as root anyway (normally refused: it would leave files the frontend cannot write)
 *
 * Exit codes: 0 ok, 1 partly failed, 2 failed.
 */

namespace Modules\ConfigBackup\Lib;

foreach (['ApiClient', 'ApiException', 'HttpApiClient', 'Types', 'Store', 'SnapshotWriter', 'Backup', 'Remapper',
		'Restorer', 'Parts', 'Diff', 'Settings', 'Monitor', 'Runtime', 'Schedule', 'Sealer', 'Tar', 'Shipper', 'Runner',
		'dest/Remote', 'dest/S3', 'dest/Sftp', 'dest/Git'] as $file) {
	require_once __DIR__.'/../lib/'.$file.'.php';
}

$args = [];
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--([a-z_-]+)(?:=(.*))?$/s', $arg, $m)) {
		$opts[$m[1]] = $m[2] ?? true;
	}
	else {
		$args[] = $arg;
	}
}

$quiet = isset($opts['quiet']);
$say = static function (string $m) use ($quiet): void {
	if (!$quiet) {
		fwrite(STDOUT, '['.date('Y-m-d H:i:s').'] '.$m."\n");
	}
};
$fail = static function (string $m, int $code = 2): void {
	fwrite(STDERR, $m."\n");
	exit($code);
};
$opt = static fn(string $k): ?string => is_string($opts[$k] ?? null) ? $opts[$k] : null;

$command = $args[0] ?? 'help';

if ($command === 'help' || isset($opts['help'])) {
	preg_match('~/\*(.*?)\*/~s', file_get_contents(__FILE__), $m);
	echo preg_replace('/^ \* ?/m', '', trim($m[1]))."\n";
	exit(0);
}

/*
 * Commands that need nothing but files.
 */
if ($command === 'keygen') {
	[, $priv_file, $pub_file] = $args + [null, null, null];

	if ($priv_file === null || $pub_file === null) {
		$fail('Usage: keygen PRIVATE.pem PUBLIC.pem');
	}

	if (file_exists($priv_file)) {
		$fail($priv_file.' exists; not overwriting a private key.');
	}

	[$private, $public] = Sealer::generateKeyPair(4096);
	$old = umask(0077);
	file_put_contents($priv_file, $private);
	umask($old);
	file_put_contents($pub_file, $public);
	$say(sprintf('Wrote %s (keep it OFF the Zabbix server, it is the only way to read encrypted snapshots) and %s (%s). Paste the public key into the destination.',
		$priv_file, $pub_file, Sealer::fingerprint($public)
	));
	exit(0);
}

if ($command === 'decrypt') {
	$in = $args[1] ?? null;
	$key = $opt('key');

	if ($in === null || $key === null) {
		$fail('Usage: decrypt FILE.tar.cbk --key=PRIVATE.pem [--out=FILE.tar]');
	}

	$out = $opt('out') ?? preg_replace('/\.cbk$/', '', $in);

	if ($out === $in) {
		$out .= '.tar';
	}

	try {
		Sealer::decryptFile($in, $out, (string) file_get_contents($key));
	}
	catch (\Throwable $e) {
		$fail($e->getMessage());
	}

	$say('Wrote '.$out.'. Unpack with: tar xf '.$out);
	exit(0);
}

/*
 * Everything else works on a storage directory.
 */
$storage = rtrim($opt('storage') ?? (getenv('CONFIGBACKUP_STORAGE') ?: Settings::DEFAULT_STORAGE), '/');

// Running as root would leave root-owned files the frontend can no longer write.
if (function_exists('posix_geteuid') && posix_geteuid() == 0 && !isset($opts['allow-root'])) {
	$owner = is_dir($storage) && function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($storage))['name'] ?? null) : null;
	$as = $owner !== null && $owner !== 'root' ? $owner : 'www-data';

	$fail(sprintf("Do not run this as root: files it creates would lock the frontend out.\nRun it as the web server user:\n  sudo -u %s php %s %s\n(or add --allow-root if you really mean it)",
		$as, $argv[0], implode(' ', array_map('escapeshellarg', array_slice($argv, 1)))
	));
}
$store = new Store($storage);

if (($problem = $store->check()) !== null) {
	$fail($problem);
}

try {
	$runner = new Runner($storage, $say);
}
catch (\Throwable $e) {
	$fail($e->getMessage());
}

$settings = $runner->settings();

$dest = static function (?string $ref) use ($settings, $fail): array {
	foreach ($settings['destinations'] as $d) {
		if ($ref !== null && ($d['id'] === $ref || strcasecmp($d['name'], $ref) == 0)) {
			return $d;
		}
	}

	$fail(sprintf('Unknown destination "%s". Known: %s', $ref, implode(', ', array_column($settings['destinations'], 'name')) ?: 'none'));
};

try {
	switch ($command) {
		case 'run-due':
			exit($runner->runDue());

		case 'backup':
			$meta = $runner->backup(Settings::enabledTypes($settings), [
				'source' => 'cli', 'user' => Store::processUser(), 'label' => $opt('label') ?? ''
			]);
			$say(sprintf('Snapshot %s: %d objects in %.1fs, status %s.', $meta['id'], array_sum($meta['counts']),
				$meta['duration'], $meta['status']
			));

			foreach ($meta['errors'] as $type => $messages) {
				foreach ($messages as $message) {
					$say(sprintf('  error [%s] %s', $type, $message));
				}
			}

			$deleted = $store->prune($settings['keep_count'], $settings['keep_days'],
				static fn(array $m) => empty($m['schedule'])
			);

			if ($deleted) {
				$say('Pruned: '.implode(', ', $deleted));
			}

			$failed = false;
			foreach (array_filter(explode(',', $opt('to') ?? '')) as $ref) {
				$d = $dest($ref);

				try {
					$say($d['name'].': '.(new Shipper($store))->ship($meta['id'], $d));
				}
				catch (\Throwable $e) {
					$say($d['name'].' FAILED: '.$e->getMessage());
					$failed = true;
				}
			}

			exit($meta['status'] === 'ok' && !$failed ? 0 : 1);

		case 'list':
			foreach ($store->listSnapshots() as $id => $meta) {
				printf("%s  %s  %-8s %-7s %6d objects  %s%s%s\n", $id, date('Y-m-d H:i', $meta['created']),
					$meta['source'], $meta['status'], array_sum($meta['counts']), $meta['pinned'] ? '[pinned] ' : '',
					$meta['label'], !empty($meta['uploads']) ? '  -> '.implode(', ', array_keys($meta['uploads'])) : ''
				);
			}
			exit(0);

		case 'prune':
			$deleted = $store->prune($settings['keep_count'], $settings['keep_days'],
				static fn(array $m) => empty($m['schedule'])
			);

			foreach ($settings['schedules'] as $s) {
				$deleted = array_merge($deleted, $store->prune($s['keep_count'], $s['keep_days'],
					static fn(array $m) => ($m['schedule'] ?? null) === $s['id']
				));
			}

			$say($deleted ? 'Pruned: '.implode(', ', $deleted) : 'Nothing to prune.');
			exit(0);

		case 'restore':
			[, $snapshotid, $type, $id] = $args + [null, null, null, null];

			if ($snapshotid === null || $type === null || $id === null) {
				$fail('Usage: restore SNAPSHOT TYPE ID [--mode=...] [--name=...]');
			}

			if (!$store->exists($snapshotid) || !Types::exists($type)) {
				$fail('Unknown snapshot or type.');
			}

			$result = (new Restorer($runner->api(), $store, $snapshotid))
				->restore($type, $id, $opt('mode') ?? Restorer::MODE_AUTO, $opt('name'));
			$say(sprintf('%s "%s": %s', $result['ok'] ? 'OK' : 'FAILED', $result['name'], $result['message']));

			foreach ($result['notes'] as $note) {
				$say('  note: '.$note);
			}
			exit($result['ok'] ? 0 : 2);

		case 'test':
			$say((new Shipper($store))->test($dest($args[1] ?? null)));
			exit(0);

		case 'ship':
			if (!$store->exists((string) ($args[1] ?? ''))) {
				$fail('Usage: ship SNAPSHOT DEST');
			}
			$say((new Shipper($store))->ship($args[1], $dest($args[2] ?? null)));
			exit(0);

		case 'remote':
			$d = $dest($args[1] ?? null);
			foreach ((new Shipper($store))->listRemote($d) as $name => $info) {
				printf("%-40s %10s  %s\n", $name, Shipper::bytes($info['size']), date('Y-m-d H:i', $info['time']));
			}
			exit(0);

		case 'pull':
			$d = $dest($args[1] ?? null);

			if (!isset($args[2])) {
				$fail('Usage: pull DEST NAME [--key=PRIVATE.pem]');
			}

			$key = $opt('key') !== null ? (string) file_get_contents($opt('key')) : null;
			$say((new Shipper($store))->pull($d, $args[2], $key));
			exit(0);

		case 'status':
			$beat = Runtime::read($storage, 'runner');
			printf("Runner     %s\n", $beat ? sprintf('last seen %s as %s on %s, %s', date('Y-m-d H:i:s', $beat['last_seen']),
				$beat['user'], $beat['host'], ($beat['api_ok'] ?? false) ? 'API OK ('.$beat['api_message'].')' : 'API: '.($beat['api_message'] ?? '?')) : 'never ran');
			$tz = Settings::timezone($settings);
			printf("Time zone  %s%s\n", $tz, $settings['timezone'] === '' ? ' (not chosen yet: Config backup > Settings)' : '');
			$sstate = Runtime::read($storage, 'schedules');
			foreach ($settings['schedules'] as $s) {
				$st = $sstate[$s['id']] ?? [];
				if (isset($st['retry_after']) && $st['retry_after'] > time()) {
					$st['last_message'] = ($st['last_message'] ?? '').' (retry at '.Schedule::format($st['retry_after'], $tz, 'H:i').')';
				}
				printf("Schedule   %-24s %-28s next %s  last %s\n", $s['name'], Schedule::describe($s).($s['enabled'] ? '' : ' (off)'),
					Schedule::format(Schedule::nextSlot($s, time(), $tz), $tz, 'm-d H:i'),
					isset($st['last_run']) ? Schedule::format($st['last_run'], $tz, 'm-d H:i').' '.(($st['last_ok'] ?? false) ? 'ok' : 'FAILED: '.$st['last_message']) : '-'
				);
			}
			$dstate = Runtime::read($storage, 'destinations');
			foreach ($settings['destinations'] as $d) {
				$st = $dstate[$d['id']] ?? [];
				printf("Destination %-23s %-5s %s\n", $d['name'], $d['kind'], isset($st['last_time'])
					? date('m-d H:i', $st['last_time']).' '.(($st['last_ok'] ?? false) ? $st['last_message'] : 'FAILED: '.$st['last_message']) : '-');
			}
			foreach (Runtime::pending($storage) as $r) {
				printf("Queued     %s%s\n", Runtime::describe($r, $settings), $r['attempts'] ? ' (attempt '.($r['attempts'] + 1).', '.$r['last_error'].')' : '');
			}
			exit(0);

		case 'report':
			$host = $settings['monitoring']['host'];
			echo 'Host: ', $host, $settings['monitoring']['enabled'] ? '' : ' (reporting is turned off in Settings)', "\n";
			[$values, $errors] = $runner->reportNow();

			foreach ($values as $key => $value) {
				printf("  %-48s %s\n", $key, isset($errors[$key]) ? 'REFUSED: '.$errors[$key] : 'ok');
			}

			exit($errors ? 1 : 0);

		case 'log':
			foreach (Runner::logTail($storage, max(1, (int) ($args[1] ?? 50))) as $line) {
				echo $line, "\n";
			}
			exit(0);

		case 'use-storage':
			$modules = $runner->api()->call('module.get', ['output' => ['moduleid', 'config'], 'filter' => ['id' => Settings::MODULE_ID]]);

			if (!$modules) {
				$fail('The Config backup module is not registered in Zabbix (Administration > General > Modules > Scan directory).');
			}

			$config = (array) $modules[0]['config'];
			$old = $config['storage'] ?? '(default)';
			$config['storage'] = $storage;
			$runner->api()->call('module.update', ['moduleid' => $modules[0]['moduleid'], 'config' => $config]);
			$say(sprintf('Frontend storage path: %s -> %s', $old, $storage));
			exit(0);

		case 'check':
			$say('storage:  '.$storage.' (writable by '.Store::processUser().')');
			$say('key:      '.Sealer::fingerprint(Runtime::publicKey($storage)));
			$say('git:      '.(Dest\Git::available() ? 'available' : 'not installed (Git destinations will fail)'));
			$say('sftp:     '.(is_file(__DIR__.'/../vendor/autoload.php') ? 'phpseclib bundled' : 'vendor/ missing'));

			try {
				$version = $runner->api()->call('apiinfo.version', []);
				$runner->api()->call('module.get', ['output' => ['moduleid'], 'limit' => 1]);
				$say('api:      OK, Zabbix '.$version.', token has Super admin rights');
			}
			catch (\Throwable $e) {
				$say('api:      '.$e->getMessage());
				exit(1);
			}
			exit(0);

		default:
			$fail(sprintf('Unknown command "%s". Try --help.', $command));
	}
}
catch (\Throwable $e) {
	$fail($e->getMessage());
}
