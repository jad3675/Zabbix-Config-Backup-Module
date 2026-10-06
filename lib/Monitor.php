<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Zabbix monitors its own backups.
 *
 * A host (default "Config backup", in "Zabbix servers") linked to the template "Config backup by runner". The
 * runner pushes its state there on every pass with history.push (trapper items, no port 10051, the token it
 * already has). Problems then go through your normal actions and media like anything else:
 *
 *   - no word from the runner for 20m (nodata: covers a dead timer, a dead box and a broken API alike)
 *   - the runner cannot use the API
 *   - a schedule failed, or only got part of the configuration
 *   - a schedule is overdue: no successful snapshot by its next slot plus grace
 *   - sending to a destination failed
 *
 * Schedules and destinations are discovered (trapper LLD), so adding one in the module adds its items and
 * triggers in Zabbix.
 */
class Monitor {

	public const TEMPLATE = 'Config backup by runner';
	public const TEMPLATE_GROUP = 'Templates/Applications';

	private static function uuid(string $seed): string {
		$h = md5('configbackup:'.$seed);
		$h[12] = '4';
		$h[16] = dechex(8 | (hexdec($h[16]) & 3));

		return $h;
	}

	public static function template(): array {
		$t = self::TEMPLATE;

		$item = static fn(string $key, string $name, string $type, array $extra = []) => $extra + [
			'uuid' => self::uuid('item:'.$key),
			'name' => $name,
			'type' => 'TRAP',
			'key' => $key,
			'delay' => '0',
			'history' => $type === 'TEXT' ? '30d' : '90d',
			'trends' => '0',
			'value_type' => $type,
			// history.push checks the API client's IP against this. Empty is not "anyone": Zabbix then only
			// accepts loopback, and a runner calling http://<server address>/zabbix is refused.
			'allowed_hosts' => '{$CONFIGBACKUP.ALLOWED.HOSTS}',
			'tags' => [['tag' => 'component', 'value' => 'configbackup']]
		];

		$failed = static fn(string $key) => 'find(/'.$t.'/'.$key.',,"regexp","^FAILED")=1';

		return ['zabbix_export' => [
			'version' => '7.0',
			'template_groups' => [['uuid' => self::uuid('group'), 'name' => self::TEMPLATE_GROUP]],
			'templates' => [[
				'uuid' => self::uuid('template'),
				'template' => $t,
				'name' => $t,
				'description' => "Filled by the Config backup module's runner (history.push). Link it to one host; Config backup > Settings > Notify through Zabbix does that for you.",
				'groups' => [['name' => self::TEMPLATE_GROUP]],
				'items' => [
					$item('configbackup.heartbeat', 'Runner: last seen', 'UNSIGNED', [
						'units' => 'unixtime',
						'description' => 'Sent on every runner pass, normally every 5 minutes.',
						'triggers' => [[
							'uuid' => self::uuid('trigger:heartbeat'),
							'expression' => 'nodata(/'.$t.'/configbackup.heartbeat,{$CONFIGBACKUP.RUNNER.NODATA})=1',
							'name' => 'Config backup: no word from the runner for {$CONFIGBACKUP.RUNNER.NODATA}',
							'priority' => 'AVERAGE',
							'description' => 'Schedules are not running. Either the timer stopped (systemctl status zabbix-configbackup.timer), the server is down, or the runner cannot reach the Zabbix API to report. Check: sudo -u <web user> php .../bin/zbx-config-backup.php status',
							'tags' => [['tag' => 'scope', 'value' => 'availability']]
						]]
					]),
					$item('configbackup.api', 'Runner: API access', 'TEXT', [
						'triggers' => [[
							'uuid' => self::uuid('trigger:api'),
							'expression' => $failed('configbackup.api'),
							'name' => 'Config backup: the runner cannot use the Zabbix API',
							'opdata' => '{ITEM.LASTVALUE}',
							'priority' => 'AVERAGE',
							'description' => 'Check the URL and API token in Config backup > Settings.'
						]]
					]),
					$item('configbackup.last_success', 'Newest complete snapshot', 'UNSIGNED', ['units' => 'unixtime'])
				],
				'discovery_rules' => [
					[
						'uuid' => self::uuid('lld:schedules'),
						'name' => 'Backup schedules',
						'type' => 'TRAP',
						'key' => 'configbackup.schedules',
						'allowed_hosts' => '{$CONFIGBACKUP.ALLOWED.HOSTS}',
						'delay' => '0',
						'lifetime' => '1d',
						'item_prototypes' => [
							$item('configbackup.schedule.result[{#ID}]', 'Schedule "{#NAME}": last run', 'TEXT', [
								'trigger_prototypes' => [
									[
										'uuid' => self::uuid('tp:schedule.failed'),
										'expression' => $failed('configbackup.schedule.result[{#ID}]'),
										'name' => 'Config backup: schedule "{#NAME}" failed',
										'opdata' => '{ITEM.LASTVALUE}',
										'priority' => 'AVERAGE',
										'description' => 'The runner retries after 5, 10, 20 minutes, then hourly. The problem resolves on the next successful run.'
									],
									[
										'uuid' => self::uuid('tp:schedule.partial'),
										'expression' => 'find(/'.$t.'/configbackup.schedule.result[{#ID}],,"regexp","^PARTIAL")=1',
										'name' => 'Config backup: schedule "{#NAME}" could not read everything',
										'opdata' => '{ITEM.LASTVALUE}',
										'priority' => 'WARNING',
										'description' => 'A snapshot was taken, but some objects could not be read. Open it in Config backup to see which.'
									]
								]
							]),
							$item('configbackup.schedule.last_success[{#ID}]', 'Schedule "{#NAME}": last success', 'UNSIGNED', [
								'units' => 'unixtime'
							]),
							$item('configbackup.schedule.overdue[{#ID}]', 'Schedule "{#NAME}": overdue', 'UNSIGNED', [
								'description' => '1 when there has been no successful run by the slot after the last success, plus an hour.',
								'trigger_prototypes' => [[
									'uuid' => self::uuid('tp:schedule.overdue'),
									'expression' => 'last(/'.$t.'/configbackup.schedule.overdue[{#ID}])=1',
									'name' => 'Config backup: schedule "{#NAME}" is overdue',
									'priority' => 'HIGH',
									'description' => 'No successful snapshot from this schedule when one was due. You are running without a fresh backup.'
								]]
							])
						]
					],
					[
						'uuid' => self::uuid('lld:destinations'),
						'name' => 'Backup destinations',
						'type' => 'TRAP',
						'key' => 'configbackup.destinations',
						'allowed_hosts' => '{$CONFIGBACKUP.ALLOWED.HOSTS}',
						'delay' => '0',
						'lifetime' => '1d',
						'item_prototypes' => [
							$item('configbackup.destination.result[{#ID}]', 'Destination "{#NAME}": last send', 'TEXT', [
								'trigger_prototypes' => [[
									'uuid' => self::uuid('tp:destination.failed'),
									'expression' => $failed('configbackup.destination.result[{#ID}]'),
									'name' => 'Config backup: sending to "{#NAME}" failed',
									'opdata' => '{ITEM.LASTVALUE}',
									'priority' => 'WARNING',
									'description' => 'Snapshots are still taken and kept locally. A failed send is retried up to 5 times.'
								]]
							])
						]
					]
				],
				'macros' => [
					[
						'macro' => '{$CONFIGBACKUP.RUNNER.NODATA}',
						'value' => '20m',
						'description' => 'How long without a runner pass before the "no word from the runner" problem.'
					],
					[
						'macro' => '{$CONFIGBACKUP.ALLOWED.HOSTS}',
						'value' => '127.0.0.1,::1',
						'description' => 'Addresses the runner reaches the Zabbix API from. Setup puts the right ones on the host; add more there if needed.'
					]
				],
				'tags' => [['tag' => 'class', 'value' => 'software'], ['tag' => 'target', 'value' => 'configbackup']]
			]]
		]];
	}

	/**
	 * Create or update the template, the host group and the host. Run from the frontend, as the user who
	 * pressed the button, so it is in the audit log under their name.
	 *
	 * @return string  what was done
	 */
	public static function ensure(ApiClient $api, string $host, string $group, array $allowed = []): string {
		$cud = ['createMissing' => true, 'updateExisting' => true, 'deleteMissing' => true];

		$api->call('configuration.import', [
			'format' => 'json',
			'rules' => [
				'template_groups' => ['createMissing' => true, 'updateExisting' => false],
				'templates' => ['createMissing' => true, 'updateExisting' => true],
				'items' => $cud,
				'triggers' => $cud,
				'discoveryRules' => $cud,
				'valueMaps' => $cud
			],
			'source' => json_encode(self::template(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		]);

		$templateid = $api->call('template.get', ['output' => ['templateid'], 'filter' => ['host' => self::TEMPLATE]])[0]['templateid'];

		$groups = $api->call('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => $group]]);
		$groupid = $groups ? $groups[0]['groupid'] : $api->call('hostgroup.create', ['name' => $group])['groupids'][0];

		$existing = $api->call('host.get', [
			'output' => ['hostid'],
			'filter' => ['host' => $host],
			'selectParentTemplates' => ['templateid'],
			'selectHostGroups' => ['groupid']
		]);

		$allowed = implode(',', array_values(array_unique(array_merge(['127.0.0.1', '::1'], $allowed))));
		$macro = ['macro' => '{$CONFIGBACKUP.ALLOWED.HOSTS}', 'value' => $allowed,
			'description' => 'Set by Config backup setup: where the runner calls the API from.'
		];

		if (!$existing) {
			$api->call('host.create', [
				'macros' => [$macro],
				'host' => $host,
				'description' => 'Config backup runner status, pushed by the Config backup module. No interfaces needed.',
				'groups' => [['groupid' => $groupid]],
				'templates' => [['templateid' => $templateid]],
				'tags' => [['tag' => 'component', 'value' => 'configbackup']]
			]);

			return sprintf('Created host "%s" in "%s" with template "%s"; values accepted from %s.', $host, $group,
				self::TEMPLATE, $allowed
			);
		}

		$h = $existing[0];
		$templates = array_column($h['parentTemplates'], 'templateid');
		$hostgroups = array_column($h['hostgroups'], 'groupid');
		$changes = [];

		if (!in_array($templateid, $templates, true)) {
			$changes['templates'] = array_map(static fn($id) => ['templateid' => $id], array_merge($templates, [$templateid]));
		}

		if (!in_array($groupid, $hostgroups, true)) {
			$changes['groups'] = array_map(static fn($id) => ['groupid' => $id], array_merge($hostgroups, [$groupid]));
		}

		if ($changes) {
			$api->call('host.update', ['hostid' => $h['hostid']] + $changes);
		}

		$current = $api->call('usermacro.get', ['output' => ['hostmacroid', 'value'], 'hostids' => [$h['hostid']],
			'filter' => ['macro' => $macro['macro']]
		]);

		if (!$current) {
			$api->call('usermacro.create', ['hostid' => $h['hostid']] + $macro);
		}
		else {
			// Keep anything added by hand.
			$merged = array_values(array_unique(array_merge(
				array_filter(array_map('trim', explode(',', $current[0]['value']))), explode(',', $allowed)
			)));
			$api->call('usermacro.update', ['hostmacroid' => $current[0]['hostmacroid'], 'value' => implode(',', $merged)]);
		}

		return sprintf('Template "%s" updated; host "%s" %s; values accepted from %s.', self::TEMPLATE, $host,
			$changes ? 'linked to it' : 'already linked', $allowed
		);
	}

	/**
	 * Addresses the runner's API calls can come from: it runs on the frontend server and calls the configured
	 * Zabbix URL, so the client IP Zabbix sees is that URL's address (or loopback), or the server's own.
	 */
	public static function runnerAddresses(string $api_url): array {
		$ips = [];
		$host = (string) parse_url($api_url, PHP_URL_HOST);

		if ($host !== '') {
			$host = trim($host, '[]');
			$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
		}

		foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $key) {
			if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
				$ips[] = $_SERVER[$key];
			}
		}

		return array_values(array_unique($ips));
	}

	/**
	 * Everything the runner reports, as [key => value].
	 */
	public static function collect(string $storage, array $settings, array $runner, Store $store): array {
		$tz = Settings::timezone($settings);
		$now = time();
		$values = [
			'configbackup.heartbeat' => $now,
			'configbackup.api' => !empty($runner['api_ok'])
				? 'OK: '.($runner['api_message'] ?? '')
				: 'FAILED: '.($runner['api_message'] ?? 'unknown')
		];

		$newest = 0;
		foreach ($store->listSnapshots() as $meta) {
			if ($meta['status'] === 'ok' && empty($meta['pulled_from'])) {
				$newest = max($newest, (int) $meta['created']);
			}
		}

		if ($newest > 0) {
			$values['configbackup.last_success'] = $newest;
		}

		$sstate = Runtime::read($storage, 'schedules');
		$lld = [];

		foreach ($settings['schedules'] as $s) {
			if (!$s['enabled']) {
				continue;
			}

			$lld[] = ['{#ID}' => $s['id'], '{#NAME}' => $s['name']];
			$st = $sstate[$s['id']] ?? [];

			if (isset($st['last_run'])) {
				$status = !($st['last_ok'] ?? false) ? 'FAILED' : (str_contains($st['last_message'] ?? '', ', partial') ? 'PARTIAL' : 'OK');
				$values['configbackup.schedule.result['.$s['id'].']'] = $status.': '.($st['last_message'] ?? '');
			}

			$last_success = isset($st['last_success'])
				? (int) $st['last_success']
				: (($st['last_ok'] ?? false) && isset($st['last_run']) ? (int) $st['last_run'] : null);

			if ($last_success !== null) {
				$values['configbackup.schedule.last_success['.$s['id'].']'] = $last_success;
			}

			// Overdue: the slot after the last success (or after arming, if it never succeeded) plus an hour has
			// passed without a success.
			$base = $last_success ?? (int) ($st['armed'] ?? $st['created'] ?? $now);
			$due = Schedule::nextSlot($s, $base, $tz) + 3600;
			$values['configbackup.schedule.overdue['.$s['id'].']'] = $now > $due ? 1 : 0;
		}

		$values['configbackup.schedules'] = json_encode($lld, JSON_UNESCAPED_UNICODE);

		$dstate = Runtime::read($storage, 'destinations');
		$lld = [];

		foreach ($settings['destinations'] as $d) {
			if (!$d['enabled']) {
				continue;
			}

			$lld[] = ['{#ID}' => $d['id'], '{#NAME}' => $d['name']];
			$st = $dstate[$d['id']] ?? [];

			if (isset($st['last_time'])) {
				$values['configbackup.destination.result['.$d['id'].']'] =
					(($st['last_ok'] ?? false) ? 'OK: ' : 'FAILED: ').($st['last_message'] ?? '');
			}
		}

		$values['configbackup.destinations'] = json_encode($lld, JSON_UNESCAPED_UNICODE);

		return $values;
	}

	/**
	 * @return array  errors by key (items not there yet just after setup, or the host was deleted)
	 */
	public static function push(ApiClient $api, string $host, array $values): array {
		$batch = [];
		$keys = [];

		foreach ($values as $key => $value) {
			$batch[] = ['host' => $host, 'key' => $key, 'value' => (string) $value];
			$keys[] = $key;
		}

		$result = $api->call('history.push', $batch);
		$errors = [];

		foreach ($result['data'] ?? [] as $i => $row) {
			if (isset($row['error'])) {
				$errors[$keys[$i]] = $row['error'];
			}
		}

		return $errors;
	}
}
