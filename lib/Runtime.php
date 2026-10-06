<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Runner state under <storage>/.state/ (never shipped anywhere):
 *
 *   settings.json          see Settings
 *   runner.key, .pub       key pair for sealing secrets in settings.json
 *   runner.json            heartbeat: last seen, user, host, API status
 *   schedules.json         per schedule: last slot run, failures, next retry
 *   destinations.json      per destination: last upload/test, errors, remote listing, trusted host key
 *   queue/<id>.json        requests from the frontend (back up, send, pull, test, list)
 *   done/<id>.json         finished requests, last 50 kept
 *   git/<dest>/            working copies for Git destinations
 */
class Runtime {

	public static function stateDir(string $storage): string {
		$dir = rtrim($storage, '/').'/.state';

		foreach ([$dir, $dir.'/queue', $dir.'/done'] as $d) {
			if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
				throw new \RuntimeException('Cannot create '.$d);
			}
		}

		return $dir;
	}

	/*
	 * Keys.
	 */

	public static function publicKey(string $storage): string {
		$dir = self::stateDir($storage);

		if (!is_file($dir.'/runner.key') || !is_file($dir.'/runner.pub')) {
			[$private, $public] = Sealer::generateKeyPair();
			$old = umask(0077);
			file_put_contents($dir.'/runner.key.tmp', $private);
			rename($dir.'/runner.key.tmp', $dir.'/runner.key');
			file_put_contents($dir.'/runner.pub', $public);
			umask($old);
		}

		return (string) file_get_contents($dir.'/runner.pub');
	}

	public static function seal(string $storage, string $plain): string {
		return $plain === '' ? '' : Sealer::seal($plain, self::publicKey($storage));
	}

	public static function reveal(string $storage, $value): string {
		if (!Sealer::isSealed($value)) {
			return (string) $value;
		}

		$key = self::stateDir($storage).'/runner.key';

		if (!is_readable($key)) {
			throw new \RuntimeException('Runner key '.$key.' is not readable by '.Store::processUser().'.');
		}

		return Sealer::open($value, (string) file_get_contents($key));
	}

	/*
	 * Small JSON state files.
	 */

	public static function read(string $storage, string $name): array {
		$file = self::stateDir($storage).'/'.$name.'.json';

		return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
	}

	public static function write(string $storage, string $name, array $data): void {
		Store::writeJson(self::stateDir($storage).'/'.$name.'.json', $data);
	}

	public static function update(string $storage, string $name, string $key, array $changes): array {
		$data = self::read($storage, $name);
		$data[$key] = $changes + ($data[$key] ?? []);
		self::write($storage, $name, $data);

		return $data[$key];
	}

	/*
	 * Queue.
	 */

	public static function enqueue(string $storage, array $request): string {
		$id = gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
		$request += ['id' => $id, 'queued' => time(), 'not_before' => 0, 'attempts' => 0];
		Store::writeJson(self::stateDir($storage).'/queue/'.$id.'.json', $request);

		return $id;
	}

	public static function pending(string $storage): array {
		$out = [];

		foreach (glob(self::stateDir($storage).'/queue/*.json') ?: [] as $file) {
			$request = json_decode((string) file_get_contents($file), true);

			if (is_array($request)) {
				$out[$request['id']] = $request;
			}
		}

		ksort($out);

		return $out;
	}

	public static function requeue(string $storage, array $request): void {
		Store::writeJson(self::stateDir($storage).'/queue/'.$request['id'].'.json', $request);
	}

	public static function finish(string $storage, array $request, bool $ok, string $message): void {
		$dir = self::stateDir($storage);
		$request += ['finished' => time(), 'ok' => $ok, 'message' => $message];
		Store::writeJson($dir.'/done/'.$request['id'].'.json', $request);
		@unlink($dir.'/queue/'.$request['id'].'.json');

		$done = glob($dir.'/done/*.json') ?: [];
		sort($done);

		foreach (array_slice($done, 0, max(0, count($done) - 50)) as $old) {
			@unlink($old);
		}
	}

	public static function recent(string $storage, int $limit = 10): array {
		$done = glob(self::stateDir($storage).'/done/*.json') ?: [];
		rsort($done);
		$out = [];

		foreach (array_slice($done, 0, $limit) as $file) {
			$request = json_decode((string) file_get_contents($file), true);

			if (is_array($request)) {
				$out[] = $request;
			}
		}

		return $out;
	}

	public static function describe(array $request, array $settings): string {
		$dest = isset($request['destination'])
			? (Settings::destination($settings, $request['destination'])['name'] ?? $request['destination'])
			: '';

		switch ($request['kind']) {
			case 'backup':
				return 'Back up'.($request['label'] ?? '' ? ' "'.$request['label'].'"' : '');
			case 'upload':
				return sprintf('Send %s to %s', $request['snapshotid'], $dest);
			case 'pull':
				return sprintf('Pull %s from %s', $request['name'], $dest);
			case 'test':
				return 'Test '.$dest;
			case 'list':
				return 'List '.$dest;
			case 'run':
				return 'Run schedule '.(Settings::schedule($settings, $request['schedule'])['name'] ?? $request['schedule']);
		}

		return $request['kind'];
	}
}
