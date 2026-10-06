<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CControllerResponseFatal;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

/**
 * One object as JSON. For templates, hosts, maps, media types and images this is a regular Zabbix export file,
 * so it can also go through Data collection > Import on any instance.
 */
class Download extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'snapshotid' => 'required|string',
			'type' => 'required|string',
			'id' => 'required|string'
		]) && Store::isValidId($this->getInput('snapshotid')) && Types::exists($this->getInput('type'));

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
		$snapshotid = $this->getInput('snapshotid');
		$type = $this->getInput('type');
		$object = $this->store()->getObject($snapshotid, $type, $this->getInput('id'));

		if ($object === null) {
			\CMessageHelper::setErrorTitle(_('Object not found in snapshot'));
			$this->redirectTo('configbackup.snapshot', ['snapshotid' => $snapshotid]);

			return;
		}

		$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $object['name']);

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($object['data'],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			),
			'mime_type' => 'application/json',
			'page' => ['file' => sprintf('%s_%s_%s.json', $type, trim($name, '_') ?: $object['id'], $snapshotid)]
		]));
	}
}
