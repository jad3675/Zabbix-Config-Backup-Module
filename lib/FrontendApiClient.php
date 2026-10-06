<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

use API;

/**
 * Calls the API in-process with the permissions of the logged-in frontend user.
 * Only loadable inside the Zabbix frontend.
 */
class FrontendApiClient implements ApiClient {

	public function call(string $method, array $params) {
		[$service, $function] = explode('.', $method, 2);

		// Drain anything left over so we only report our own errors.
		get_and_clear_messages();

		try {
			$result = API::getApi($service)->$function($params);
		}
		catch (\Throwable $e) {
			throw new ApiException(sprintf('%s: %s', $method, $e->getMessage()));
		}

		if ($result === false) {
			$errors = array_column(get_and_clear_messages(), 'message');

			throw new ApiException(sprintf('%s: %s', $method,
				$errors ? implode('; ', $errors) : 'unknown API error'
			));
		}

		return $result;
	}
}
