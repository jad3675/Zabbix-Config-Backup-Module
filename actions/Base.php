<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CController;
use CControllerResponseRedirect;
use CUrl;
use CWebUser;
use Modules\ConfigBackup\Lib\ApiClient;
use Modules\ConfigBackup\Lib\FrontendApiClient;
use Modules\ConfigBackup\Lib\Runtime;
use Modules\ConfigBackup\Lib\Settings;
use Modules\ConfigBackup\Lib\Store;

/**
 * Everything in this module is Super admin only: a snapshot holds users, roles and every action in the system.
 */
abstract class Base extends CController {

	// The runner timer fires every 5 minutes; older than this means it has stopped.
	public const RUNNER_STALE = 900;

	private ?ApiClient $client = null;
	private ?array $module_config = null;
	private ?array $settings = null;

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function api(): ApiClient {
		return $this->client ??= new FrontendApiClient();
	}

	protected function moduleConfig(): array {
		return $this->module_config ??= Settings::moduleConfig($this->api());
	}

	protected function storage(): string {
		return $this->moduleConfig()['storage'];
	}

	protected function settings(): array {
		return $this->settings ??= Settings::load($this->storage(), $this->moduleConfig());
	}

	protected function saveSettings(array $settings): void {
		Settings::save($this->storage(), $settings);
		$this->settings = Settings::load($this->storage());
	}

	protected function store(): Store {
		return new Store($this->storage());
	}

	protected function enqueue(array $request): string {
		return Runtime::enqueue($this->storage(), $request + ['user' => $this->username()]);
	}

	protected function runnerStatus(): array {
		$beat = $this->store()->check() === null ? Runtime::read($this->storage(), 'runner') : [];

		return $beat + ['alive' => isset($beat['last_seen']) && $beat['last_seen'] > time() - self::RUNNER_STALE];
	}

	protected function username(): string {
		return (string) (CWebUser::$data['username'] ?? '');
	}

	protected static function url(string $action, array $args = []): CUrl {
		$url = (new CUrl('zabbix.php'))->setArgument('action', $action);

		foreach ($args as $key => $value) {
			if ($value !== null && $value !== '') {
				$url->setArgument($key, $value);
			}
		}

		return $url;
	}

	protected function redirectTo(string $action, array $args = []): void {
		$this->setResponse(new CControllerResponseRedirect(self::url($action, $args)));
	}

	protected static function unlimited(): void {
		@set_time_limit(0);
		@ignore_user_abort(true);
		@ini_set('memory_limit', '1G');
	}
}
