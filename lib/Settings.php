<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Settings live in <storage>/.state/settings.json, written by the frontend and read by the runner. Both run as
 * the web server user. The only thing kept in the Zabbix DB (module config) is the storage path itself, because
 * the frontend needs it to find everything else; the runner gets it from --storage.
 *
 * Secrets (API token, destination passwords and keys) are stored sealed to the runner's key pair and never
 * travel back to the browser.
 */
class Settings {

	public const MODULE_ID = 'configbackup';
	public const DEFAULT_STORAGE = '/var/lib/zabbix-configbackup';
	// Default before 1.4.0; install-runner.sh offers to move it.
	public const LEGACY_STORAGE = '/var/lib/zabbix/configbackup';

	public const DEFAULTS = [
		'version' => 2,
		'api' => ['url' => '', 'token' => ''],
		'keep_count' => 30,
		'keep_days' => 0,
		'expect_hours' => 24,
		'timezone' => '',        // schedules run in this zone; empty = UTC until one is chosen
		'types' => [],
		'schedules' => [],
		'destinations' => [],
		// Report to Zabbix itself: see Monitor.
		'monitoring' => ['enabled' => false, 'host' => 'Config backup', 'group' => 'Zabbix servers']
	];

	public const SCHEDULE_DEFAULTS = [
		'id' => '',
		'name' => '',
		'enabled' => true,
		'every' => 'daily',      // hours | daily | weekly
		'hours' => 6,
		'at' => '02:30',
		'weekday' => 0,          // 0 = Sunday
		'types' => [],           // empty = all
		'destinations' => [],
		'keep_count' => 30,
		'keep_days' => 0
	];

	public const DESTINATION_DEFAULTS = [
		'id' => '',
		'name' => '',
		'kind' => 's3',          // s3 | sftp | git
		'enabled' => true,
		'manual' => false,       // also receive snapshots taken with "Back up now"
		'keep_count' => 30,
		'keep_days' => 0,
		'public_key' => '',      // PEM: encrypt before upload (s3, sftp)
		'private_key_path' => '',// optional, on this server: lets the runner pull encrypted snapshots back
		// s3
		'endpoint' => '', 'region' => 'us-east-1', 'bucket' => '', 'prefix' => 'zabbix-configbackup',
		'path_style' => false, 'access_key' => '', 'secret_key' => '', 'sse' => '',
		// sftp
		'host' => '', 'port' => 22, 'username' => '', 'password' => '', 'ssh_key' => '', 'ssh_key_passphrase' => '',
		'host_key' => '', 'path' => 'zabbix-configbackup',
		// git
		'url' => '', 'branch' => 'main', 'git_auth' => 'ssh', 'git_user' => '', 'git_token' => '',
		'known_hosts' => '', 'author_name' => 'Zabbix config backup', 'author_email' => 'zabbix@localhost',
		'git_exclude_people' => true
	];

	public const SECRET_FIELDS = ['token', 'secret_key', 'password', 'ssh_key', 'ssh_key_passphrase', 'git_token'];

	public static function file(string $storage): string {
		return rtrim($storage, '/').'/.state/settings.json';
	}

	public static function load(string $storage, array $legacy = []): array {
		$file = self::file($storage);
		$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

		if (!is_array($data)) {
			// First run after upgrading from 1.0: retention and types came from module config.
			$data = array_intersect_key($legacy, array_flip(['keep_count', 'keep_days', 'expect_hours', 'types']));
		}

		return self::normalize($data);
	}

	public static function save(string $storage, array $settings): void {
		Runtime::stateDir($storage);
		Store::writeJson(self::file($storage), self::normalize($settings));
		@chmod(self::file($storage), 0600);
	}

	public static function normalize(array $s): array {
		$s += self::DEFAULTS;
		$s['api'] = (array) $s['api'] + self::DEFAULTS['api'];
		$s['monitoring'] = (array) $s['monitoring'] + self::DEFAULTS['monitoring'];
		$s['monitoring']['enabled'] = (bool) $s['monitoring']['enabled'];
		$s['monitoring']['host'] = trim((string) $s['monitoring']['host']) ?: 'Config backup';
		$s['monitoring']['group'] = trim((string) $s['monitoring']['group']) ?: 'Zabbix servers';
		$s['keep_count'] = max(1, (int) $s['keep_count']);
		$s['keep_days'] = max(0, (int) $s['keep_days']);
		$s['expect_hours'] = max(0, (int) $s['expect_hours']);
		$s['timezone'] = in_array((string) $s['timezone'], \DateTimeZone::listIdentifiers(), true) ? $s['timezone'] : '';
		$s['types'] = array_values(array_filter((array) $s['types'], [Types::class, 'exists']));

		$s['schedules'] = array_values(array_map(static function ($x) {
			$x = (array) $x + self::SCHEDULE_DEFAULTS;
			$x['enabled'] = (bool) $x['enabled'];
			$x['every'] = in_array($x['every'], ['hours', 'daily', 'weekly'], true) ? $x['every'] : 'daily';
			$x['hours'] = max(1, min(24, (int) $x['hours']));
			$x['at'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $x['at']) ? $x['at'] : '02:30';
			$x['weekday'] = max(0, min(6, (int) $x['weekday']));
			$x['types'] = array_values(array_filter((array) $x['types'], [Types::class, 'exists']));
			$x['destinations'] = array_values(array_map('strval', (array) $x['destinations']));
			$x['keep_count'] = max(1, (int) $x['keep_count']);
			$x['keep_days'] = max(0, (int) $x['keep_days']);

			return array_intersect_key($x, self::SCHEDULE_DEFAULTS);
		}, (array) $s['schedules']));

		$s['destinations'] = array_values(array_map(static function ($x) {
			$x = (array) $x + self::DESTINATION_DEFAULTS;
			$x['kind'] = in_array($x['kind'], ['s3', 'sftp', 'git'], true) ? $x['kind'] : 's3';

			foreach (['enabled', 'manual', 'path_style', 'git_exclude_people'] as $k) {
				$x[$k] = (bool) $x[$k];
			}

			$x['port'] = max(1, min(65535, (int) $x['port']));
			$x['keep_count'] = max(1, (int) $x['keep_count']);
			$x['keep_days'] = max(0, (int) $x['keep_days']);
			$x['prefix'] = trim((string) $x['prefix'], '/');
			$x['path'] = rtrim((string) $x['path'], '/');

			return array_intersect_key($x, self::DESTINATION_DEFAULTS);
		}, (array) $s['destinations']));

		return array_intersect_key($s, self::DEFAULTS);
	}

	/**
	 * Types for "Back up now": empty selection means all.
	 */
	public static function enabledTypes(array $settings): array {
		return $settings['types'] ?: array_keys(Types::all());
	}

	/**
	 * The zone schedules are calculated in, the same for the runner, the frontend and the command line,
	 * whatever php.ini or the Zabbix user profile say.
	 */
	public static function timezone(array $settings): string {
		return $settings['timezone'] !== '' ? $settings['timezone'] : 'UTC';
	}

	public static function schedule(array $settings, string $id): ?array {
		foreach ($settings['schedules'] as $schedule) {
			if ($schedule['id'] === $id) {
				return $schedule;
			}
		}

		return null;
	}

	public static function destination(array $settings, string $id): ?array {
		foreach ($settings['destinations'] as $destination) {
			if ($destination['id'] === $id) {
				return $destination;
			}
		}

		return null;
	}

	public static function newId(): string {
		return bin2hex(random_bytes(4));
	}

	/**
	 * Storage path from the module config in the Zabbix DB (frontend side).
	 */
	public static function moduleConfig(ApiClient $api): array {
		$modules = $api->call('module.get', [
			'output' => ['moduleid', 'config'],
			'filter' => ['id' => self::MODULE_ID]
		]);

		$config = $modules ? (array) $modules[0]['config'] : [];
		$config['moduleid'] = $modules ? $modules[0]['moduleid'] : null;
		$config['storage'] = rtrim(trim((string) ($config['storage'] ?? '')), '/') ?: self::DEFAULT_STORAGE;

		return $config;
	}
}
