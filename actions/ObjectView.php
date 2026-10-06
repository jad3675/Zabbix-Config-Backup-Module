<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Actions;

use CControllerResponseData;
use CCsrfTokenHelper;
use CMessageHelper;
use Modules\ConfigBackup\Lib\ApiException;
use Modules\ConfigBackup\Lib\Diff;
use Modules\ConfigBackup\Lib\Parts;
use Modules\ConfigBackup\Lib\Remapper;
use Modules\ConfigBackup\Lib\Restorer;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

class ObjectView extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'snapshotid' => 'required|string',
			'type' => 'required|string',
			'id' => 'required|string',
			'back' => 'string',
			'all_parts' => 'in 0,1'
		]) && Store::isValidId($this->getInput('snapshotid')) && Types::exists($this->getInput('type'));

		if (!$ret) {
			$this->redirectTo('configbackup.list');
		}

		return $ret;
	}

	protected function doAction(): void {
		$snapshotid = $this->getInput('snapshotid');
		$type = $this->getInput('type');
		$id = $this->getInput('id');
		$def = Types::get($type);
		$store = $this->store();

		$object = $store->exists($snapshotid) ? $store->getObject($snapshotid, $type, $id) : null;

		if ($object === null) {
			CMessageHelper::setErrorTitle(_('Object not found in snapshot'));
			$this->redirectTo('configbackup.list');

			return;
		}

		$restorer = new Restorer($this->api(), $store, $snapshotid);
		$live = null;
		$live_id = null;
		$error = null;
		$lookup = $object['name'];

		if ($def['mode'] === Types::MODE_EXPORT && $def['export_tech'] !== null) {
			$lookup = (string) ($object['data']['zabbix_export'][$def['section_key']][0][$def['export_tech']]
				?? $object['name']);
		}

		try {
			$live_id = $restorer->findLiveId($type, $id, $lookup);
			$live = $live_id !== null ? $restorer->fetchLive($type, $live_id) : null;
		}
		catch (ApiException $e) {
			$error = $e->getMessage();
		}

		// Compare like with like: references in the snapshot are pointed at what they resolve to today, so a
		// recreated user group doesn't show up as a change on every action that uses it.
		$snap_data = $object['data'];
		if ($def['mode'] === Types::MODE_API) {
			$snap_data = Remapper::apply($type, $snap_data, [$restorer, 'resolveRef']);
		}

		$snap_text = Restorer::canonical($type, $snap_data);
		$live_text = $live !== null ? Restorer::canonical($type, $live) : '';

		if ($live_id === null) {
			$state = 'missing';
		}
		elseif ($snap_text === $live_text) {
			$state = $live_id === $id || $def['mode'] === Types::MODE_SINGLETON ? 'same' : 'recreated';
		}
		else {
			$state = $live_id === $id || $def['mode'] === Types::MODE_SINGLETON ? 'changed' : 'recreated';
		}

		$hunks = $live !== null && $snap_text !== $live_text
			? Diff::hunks(Diff::lines($live_text, $snap_text), 4)
			: [];

		// Templates and hosts: their pieces, each compared with the live object.
		$parts = [];

		if (in_array($type, ['template', 'host'], true)) {
			$snap_parts = Parts::index($object['data'], $type);
			$live_parts = $live !== null ? Parts::index($live, $type) : [];
			$states = $live !== null ? Parts::compare($snap_parts, $live_parts) : [];
			$order = ['missing' => 0, 'changed' => 1, 'same' => 2];
			$kinds = array_flip(array_keys(Parts::KINDS));

			foreach ($snap_parts as $key => $part) {
				$parts[] = [
					'key' => $key,
					'kind' => $part['kind'],
					'name' => $part['name'],
					'detail' => $part['detail'],
					'state' => $states[$key] ?? null
				];
			}

			usort($parts, static fn($a, $b) => [$order[$a['state']] ?? 3, $kinds[$a['kind']], $a['name']]
				<=> [$order[$b['state']] ?? 3, $kinds[$b['kind']], $b['name']]
			);
		}

		$this->setResponse(new CControllerResponseData([
			'parts' => $parts,
			'show_all_parts' => $this->getInput('all_parts', 0) == 1,
			'title' => _('Config backup'),
			'meta' => $store->getMeta($snapshotid),
			'type' => $type,
			'def' => $def,
			'object' => $object,
			'snap_text' => $snap_text,
			'live_id' => $live_id,
			'state' => $state,
			'hunks' => $hunks,
			'error' => $error,
			'modes' => Restorer::modesFor($type),
			'back' => $this->getInput('back', ''),
			'csrf' => [
				'restore' => CCsrfTokenHelper::get('configbackup.restore'),
				'parts' => CCsrfTokenHelper::get('configbackup.restore.parts')
			]
		]));
	}
}
