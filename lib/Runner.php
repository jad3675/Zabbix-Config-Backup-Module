<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * One pass of the background runner (systemd timer / cron every 5 minutes, as the web server user):
 * heartbeat, queued requests from the frontend, then due schedules. Exits as soon as there is nothing to do.
 *
 * Toward Zabbix the runner only reads. Restores are done in the frontend by the logged-in user, so they land in
 * the audit log under a person's name.
 */
class Runner {

	public const VERSION = '1.4.1';
	private const RETRY_MAX = 5;

	private string $storage;
	private Store $store;
	private array $settings;
	private $log;
	private ?ApiClient $api = null;
	private ?string $api_error = null;

	public function __construct(string $storage, ?callable $log = null) {
		$this->storage = rtrim($storage, '/');
		$this->store = new Store($this->storage);
		$this->settings = Settings::load($this->storage);
		$this->log = $log ?? static function (string $m): void {};
	}

	/**
	 * Everything the runner decides goes to <storage>/.state/runner.log (rotated at 1 MB, one old file kept),
	 * whether or not --quiet is given, so "why didn't it run?" always has an answer.
	 */
	private function log(string $message): void {
		($this->log)($message);

		try {
			$file = Runtime::stateDir($this->storage).'/runner.log';

			if (is_file($file) && filesize($file) > 1048576) {
				@rename($file, $file.'.1');
			}

			$tz = Settings::timezone($this->settings);
			@file_put_contents($file, Schedule::format(time(), $tz, 'Y-m-d H:i:s T').' '.$message."\n", FILE_APPEND | LOCK_EX);
		}
		catch (\Throwable $e) {
			// Logging must never stop a backup.
		}
	}

	public static function logTail(string $storage, int $lines = 50): array {
		$file = rtrim($storage, '/').'/.state/runner.log';

		if (!is_readable($file)) {
			return [];
		}

		$all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

		return array_slice($all, -$lines);
	}

	public function settings(): array {
		return $this->settings;
	}

	public function store(): Store {
		return $this->store;
	}

	/**
	 * API client from the sealed token in settings.
	 */
	public function api(): ApiClient {
		if ($this->api !== null) {
			return $this->api;
		}

		$api = $this->settings['api'];

		if ($api['url'] === '' || $api['token'] === '') {
			throw new \RuntimeException('Zabbix URL and API token are not set (Config backup > Settings).');
		}

		return $this->api = new HttpApiClient($api['url'], Runtime::reveal($this->storage, $api['token']),
			$api['verify_tls'], null, 300, $api['ca_file']
		);
	}

	/**
	 * Same check the Settings page runs when saved. Static so the frontend can use it with the token it holds.
	 */
	public static function testApi(string $url, string $token, bool $verify_tls = true, string $ca_file = ''): string {
		$api = new HttpApiClient($url, $token, $verify_tls, null, 20, $ca_file);
		$version = $api->call('apiinfo.version', []);
		$api->call('module.get', ['output' => ['moduleid'], 'limit' => 1]);

		return sprintf('Zabbix %s at %s, token has Super admin rights', $version, $api->getUrl());
	}

	private function checkApi(): array {
		try {
			$version = $this->api()->call('apiinfo.version', []);
			// module.get needs Super admin: proves the token can read everything a snapshot needs.
			$this->api()->call('module.get', ['output' => ['moduleid'], 'limit' => 1]);

			return ['api_ok' => true, 'api_message' => 'Zabbix '.$version, 'api_checked' => time()];
		}
		catch (\Throwable $e) {
			$this->api_error = $e->getMessage();

			return ['api_ok' => false, 'api_message' => $e->getMessage(), 'api_checked' => time()];
		}
	}

	private function heartbeat(array $extra = []): void {
		$beat = Runtime::read($this->storage, 'runner');
		Runtime::write($this->storage, 'runner', $extra + [
			'last_seen' => time(),
			'user' => Store::processUser(),
			'host' => gethostname(),
			'php' => PHP_VERSION,
			'version' => self::VERSION
		] + $beat);
	}

	/**
	 * @return int  0 ok, 1 something failed
	 */
	public function runDue(): int {
		$problem = $this->store->check();

		if ($problem !== null) {
			throw new \RuntimeException($problem);
		}

		$lock = fopen(Runtime::stateDir($this->storage).'/runner.lock', 'c');

		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			$this->log('Another runner pass is still going; leaving it alone.');

			return 0;
		}

		$failed = false;

		try {
			$api = $this->checkApi();
			$this->heartbeat($api + ['pass_started' => time()]);

			if (!$api['api_ok']) {
				$this->log('API check failed: '.$api['api_message']);
			}

			foreach (Runtime::pending($this->storage) as $request) {
				if ($request['not_before'] > time()) {
					continue;
				}

				$failed = !$this->handle($request) || $failed;
			}

			$this->refreshListings();

			foreach ($this->settings['schedules'] as $schedule) {
				if ($schedule['enabled']) {
					$failed = !$this->scheduleTick($schedule) || $failed;
				}
			}

			if ($this->settings['monitoring']['enabled'] && $api['api_ok']) {
				$this->report();
			}
		}
		finally {
			$this->heartbeat(['pass_finished' => time()]);
			flock($lock, LOCK_UN);
			fclose($lock);
		}

		return $failed ? 1 : 0;
	}

	/*
	 * Queue.
	 */

	public function handle(array $request): bool {
		$what = Runtime::describe($request, $this->settings);
		$this->log($what.'...');

		try {
			$message = $this->execute($request);
			Runtime::finish($this->storage, $request, true, $message);
			$this->log('  '.$message);

			return true;
		}
		catch (\Throwable $e) {
			$request['attempts']++;
			// Retry uploads (network, remote outage); not requests that can never work.
			$retry = $request['kind'] === 'upload' && $request['attempts'] < self::RETRY_MAX
				&& Settings::destination($this->settings, $request['destination']) !== null
				&& $this->store->exists($request['snapshotid']);

			if ($retry) {
				$request['not_before'] = time() + min(3600, 300 * 2 ** ($request['attempts'] - 1));
				$request['last_error'] = $e->getMessage();
				Runtime::requeue($this->storage, $request);
				$this->log(sprintf('  failed, retry %d/%d at %s: %s', $request['attempts'], self::RETRY_MAX,
					date('H:i', $request['not_before']), $e->getMessage()
				));
			}
			else {
				Runtime::finish($this->storage, $request, false, $e->getMessage());
				$this->log('  failed: '.$e->getMessage());
			}

			return false;
		}
	}

	private function destination(string $id): array {
		$d = Settings::destination($this->settings, $id);

		if ($d === null) {
			throw new \RuntimeException('Destination '.$id.' no longer exists.');
		}

		return $d;
	}

	private function execute(array $r): string {
		$shipper = new Shipper($this->store);

		switch ($r['kind']) {
			case 'backup':
				$meta = $this->backup(($r['types'] ?? []) ?: Settings::enabledTypes($this->settings), [
					'source' => 'manual', 'user' => $r['user'] ?? '', 'label' => $r['label'] ?? ''
				]);
				$this->store->prune($this->settings['keep_count'], $this->settings['keep_days'],
					static fn(array $m) => empty($m['schedule'])
				);
				$sent = $this->shipAll($meta['id'], $r['destinations'] ?? []);

				return sprintf('Snapshot %s: %d objects%s.', $meta['id'], array_sum($meta['counts']), $sent);

			case 'run':
				$schedule = Settings::schedule($this->settings, $r['schedule']);

				if ($schedule === null) {
					throw new \RuntimeException('Schedule no longer exists.');
				}

				return $this->runSchedule($schedule);

			case 'upload':
				return $shipper->ship($r['snapshotid'], $this->destination($r['destination']));

			case 'pull':
				return $shipper->pull($this->destination($r['destination']), $r['name']);

			case 'test':
				return $shipper->test($this->destination($r['destination']));

			case 'list':
				return sprintf('%d snapshot(s) on %s.', count($shipper->listRemote($this->destination($r['destination']))),
					$this->destination($r['destination'])['name']
				);
		}

		throw new \RuntimeException('Unknown request '.$r['kind']);
	}

	public function backup(array $types, array $meta): array {
		$started = time();

		try {
			$meta = (new Backup($this->api(), $this->store))->run($types, $meta);
		}
		catch (\Throwable $e) {
			$this->store->writeLastRun(['source' => $meta['source'], 'time' => $started, 'status' => 'failed',
				'message' => $e->getMessage()
			]);

			throw $e;
		}

		$this->store->writeLastRun(['source' => $meta['source'], 'time' => $started, 'status' => $meta['status'],
			'snapshot' => $meta['id'], 'objects' => array_sum($meta['counts']), 'duration' => $meta['duration'],
			'message' => $meta['errors'] ? count($meta['errors']).' type(s) with errors' : ''
		]);

		return $meta;
	}

	/**
	 * Send a snapshot to several destinations. A failed upload is queued for retry instead of failing the run.
	 */
	private function shipAll(string $snapshotid, array $destids): string {
		$shipper = new Shipper($this->store);
		$sent = [];

		foreach ($destids as $destid) {
			$d = Settings::destination($this->settings, $destid);

			if ($d === null || !$d['enabled']) {
				continue;
			}

			try {
				$this->log('  -> '.$d['name'].': '.$shipper->ship($snapshotid, $d));
				$sent[] = $d['name'];
			}
			catch (\Throwable $e) {
				$this->log('  -> '.$d['name'].' failed, queued for retry: '.$e->getMessage());
				Runtime::enqueue($this->storage, ['kind' => 'upload', 'snapshotid' => $snapshotid,
					'destination' => $destid, 'attempts' => 1, 'not_before' => time() + 300,
					'last_error' => $e->getMessage()
				]);
				$sent[] = $d['name'].' (failed, retrying)';
			}
		}

		return $sent ? ', sent to '.implode(', ', $sent) : '';
	}

	/**
	 * Push the runner's state to the "Config backup" host in Zabbix (see Monitor). Logged when the outcome
	 * changes, and hourly while it keeps failing.
	 */
	public function reportNow(): array {
		$beat = Runtime::read($this->storage, 'runner');
		$values = Monitor::collect($this->storage, $this->settings, $beat, $this->store);

		return [$values, Monitor::push($this->api(), $this->settings['monitoring']['host'], $values)];
	}

	private function report(): void {
		$beat = Runtime::read($this->storage, 'runner');
		$host = $this->settings['monitoring']['host'];

		try {
			$errors = Monitor::push($this->api(), $host,
				Monitor::collect($this->storage, $this->settings, $beat, $this->store)
			);

			// Items of a schedule added a minute ago may not exist yet: LLD creates them from this push.
			$hard = $errors['configbackup.heartbeat'] ?? null;
			$ok = $hard === null;

			if ($hard !== null && stripos($hard, 'allowed hosts') !== false) {
				$message = sprintf('Zabbix refused the values: %s Press "Update host and template" in Settings > Notify through Zabbix (it adds this server\'s addresses to {$CONFIGBACKUP.ALLOWED.HOSTS} on host "%s"), or add the address yourself.',
					$hard, $host
				);
			}
			elseif ($hard !== null) {
				$message = sprintf('Zabbix refused the values for host "%s": %s Right after setup this is normal until the Zabbix server reloads its configuration cache (a minute or two). If it persists, check the host exists and is linked to "%s", or run the "report" command.',
					$host, $hard, Monitor::TEMPLATE
				);
			}
			else {
				$message = $errors ? sprintf('reported, %d discovered item(s) not loaded by the server yet', count($errors)) : 'reported';
			}
		}
		catch (\Throwable $e) {
			$ok = false;
			$message = $e->getMessage();
		}

		// Log changes, and a persisting failure once an hour.
		if (($beat['monitor_ok'] ?? null) !== $ok || (!$ok && ($beat['monitor_logged'] ?? 0) < time() - 3600)) {
			$beat['monitor_logged'] = time();
			Runtime::write($this->storage, 'runner', ['monitor_logged' => time()] + Runtime::read($this->storage, 'runner'));
			$this->log(sprintf('Reporting to Zabbix host "%s": %s', $host, $ok ? 'working' : 'FAILED: '.$message));
		}

		$this->heartbeat(['monitor_ok' => $ok, 'monitor_message' => $message, 'monitor_time' => time()]);
	}

	/**
	 * Keep each destination's list of snapshots reasonably fresh (every 6 hours) so the snapshot list can show
	 * remote-only copies without anyone pressing Refresh.
	 */
	private function refreshListings(): void {
		$state = Runtime::read($this->storage, 'destinations');
		$shipper = new Shipper($this->store);

		foreach ($this->settings['destinations'] as $d) {
			if (!$d['enabled'] || $d['kind'] === 'git' || ($state[$d['id']]['listing_time'] ?? 0) > time() - 6 * 3600) {
				continue;
			}

			try {
				$count = count($shipper->listRemote($d));
				$this->log(sprintf('Listed %s: %d snapshot(s).', $d['name'], $count));
			}
			catch (\Throwable $e) {
				// Don't retry every 5 minutes against a destination that is down.
				Runtime::update($this->storage, 'destinations', $d['id'], ['listing_time' => time()]);
				$this->log(sprintf('Listing %s failed: %s', $d['name'], $e->getMessage()));
			}
		}
	}

	/*
	 * Schedules.
	 */

	private function scheduleTick(array $s): bool {
		$now = time();
		$slot = Schedule::lastSlot($s, $now, Settings::timezone($this->settings));
		$state = Runtime::read($this->storage, 'schedules')[$s['id']] ?? null;

		$tz = Settings::timezone($this->settings);

		// Never armed (schedule written outside the frontend): start with the next slot.
		if ($state === null || !isset($state['last_slot'])) {
			Runtime::update($this->storage, 'schedules', $s['id'], ['last_slot' => $slot, 'armed' => $now]);
			$this->log(sprintf('Schedule "%s": armed, first run %s %s.', $s['name'],
				Schedule::format(Schedule::nextSlot($s, $now, $tz), $tz), $tz
			));

			return true;
		}

		if ($slot <= $state['last_slot']) {
			return true;
		}

		if (($state['retry_after'] ?? 0) > $now) {
			return true;
		}

		if (($state['failures'] ?? 0) > 0) {
			$this->log(sprintf('Schedule "%s": retry %d.', $s['name'], $state['failures']));
		}

		$this->log(sprintf('Schedule "%s" (%s)...', $s['name'], Schedule::describe($s)));

		try {
			$message = $this->runSchedule($s);
			Runtime::update($this->storage, 'schedules', $s['id'], [
				'last_slot' => $slot, 'failures' => 0, 'retry_after' => 0
			]);
			$this->log('  '.$message);

			return true;
		}
		catch (\Throwable $e) {
			$failures = ($state['failures'] ?? 0) + 1;
			Runtime::update($this->storage, 'schedules', $s['id'], [
				'failures' => $failures,
				'retry_after' => $now + min(3600, 300 * 2 ** ($failures - 1)),
				'last_run' => $now, 'last_ok' => false, 'last_message' => $e->getMessage()
			]);
			$this->log('  failed: '.$e->getMessage());

			return false;
		}
	}

	private function runSchedule(array $s): string {
		$meta = $this->backup($s['types'] ?: array_keys(Types::all()), [
			'source' => 'schedule', 'user' => Store::processUser(), 'label' => $s['name'], 'schedule' => $s['id']
		]);

		$deleted = $this->store->prune($s['keep_count'], $s['keep_days'],
			static fn(array $m) => ($m['schedule'] ?? null) === $s['id']
		);

		$message = sprintf('Snapshot %s: %d objects, %s%s%s.', $meta['id'], array_sum($meta['counts']),
			$meta['status'], $deleted ? sprintf(', retention removed %d', count($deleted)) : '',
			$this->shipAll($meta['id'], $s['destinations'])
		);

		Runtime::update($this->storage, 'schedules', $s['id'], [
			'last_run' => time(), 'last_ok' => true, 'last_message' => $message, 'last_snapshot' => $meta['id'],
			'last_success' => time()
		]);

		return $message;
	}
}
