<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Minimal API surface the engine needs. Two implementations: the frontend one (runs inside a logged-in
 * Zabbix session) and the HTTP one (cron/CLI, API token).
 */
interface ApiClient {

	/**
	 * @throws ApiException
	 */
	public function call(string $method, array $params);
}
