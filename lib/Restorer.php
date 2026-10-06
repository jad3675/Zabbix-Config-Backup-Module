<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Compares snapshot objects with the live system and puts them back.
 */
class Restorer {

	// Object is gone: create it again (optionally under a new name, which also makes it a copy).
	public const MODE_CREATE = 'create';
	// Object exists: replace its configuration with the snapshot version.
	public const MODE_OVERWRITE = 'overwrite';
	// Templates/hosts/maps/...: create the object if missing and any missing parts (items, triggers...);
	// leave everything that exists alone.
	public const MODE_MISSING = 'missing';
	// Templates/hosts: overwrite, and also delete parts added since the snapshot. A true rollback.
	public const MODE_EXACT = 'exact';
	// Bulk: create if missing, skip if it still exists.
	public const MODE_AUTO = 'auto';
	// Bulk: like auto, but templates/hosts/maps/... that still exist get their missing parts back (deleted items,
	// triggers, template links). The usual follow-up to a deleted template: restore it, then this on its hosts.
	public const MODE_AUTO_PARTS = 'auto_parts';

	public const STATE_MISSING = 'missing';
	public const STATE_RECREATED = 'recreated';
	public const STATE_CHANGED = 'changed';
	public const STATE_SAME = 'same';
	public const STATE_PRESENT = 'present';

	private ApiClient $api;
	private Store $store;
	private string $snapshotid;
	private array $remap;

	public function __construct(ApiClient $api, Store $store, string $snapshotid) {
		$this->api = $api;
		$this->store = $store;
		$this->snapshotid = $snapshotid;
		$this->remap = $store->getRemap($snapshotid);
	}

	public static function modesFor(string $type): array {
		switch (Types::get($type)['mode']) {
			case Types::MODE_SINGLETON:
				return [self::MODE_OVERWRITE];

			case Types::MODE_EXPORT:
				return in_array($type, ['template', 'host'], true)
					? [self::MODE_MISSING, self::MODE_CREATE, self::MODE_OVERWRITE, self::MODE_EXACT]
					: [self::MODE_MISSING, self::MODE_CREATE, self::MODE_OVERWRITE];

			default:
				return [self::MODE_CREATE, self::MODE_OVERWRITE];
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------
	 * Live state
	 * ---------------------------------------------------------------------------------------------------------
	 */

	/**
	 * State of every snapshot object of a type compared to the live system.
	 *
	 * @return array  id => ['state' => STATE_*, 'live_id' => ?string]
	 */
	public function states(string $type): array {
		$def = Types::get($type);
		$index = $this->store->getIndex($this->snapshotid, $type);
		$states = [];

		if (!$index) {
			return $states;
		}

		if ($def['mode'] === Types::MODE_SINGLETON) {
			$snap = $this->store->getObject($this->snapshotid, $type, '0');
			$live = $this->api->call($def['service'].'.get', $def['get']);
			$same = self::fingerprint($type, $snap['data']) === self::fingerprint($type, $live);

			return ['0' => ['state' => $same ? self::STATE_SAME : self::STATE_CHANGED, 'live_id' => '0']];
		}

		$by_id = [];
		$by_name = [];

		if ($def['mode'] === Types::MODE_API) {
			foreach ($this->api->call($def['service'].'.get', $def['get']) as $object) {
				$by_id[(string) $object[$def['id']]] = $object;
				$by_name[(string) $object[$def['name']]] = (string) $object[$def['id']];
			}

			$snap_objects = $this->store->getObjects($this->snapshotid, $type, array_column($index, 'id'));
		}
		else {
			$match = $def['tech'] ?? $def['name'];

			foreach ($this->api->call($def['service'].'.get', $def['list']) as $object) {
				$by_id[(string) $object[$def['id']]] = $object;
				$by_name[(string) $object[$match]] = (string) $object[$def['id']];
			}
		}

		foreach ($index as $entry) {
			$id = $entry['id'];
			$name = $entry['tech'] ?? $entry['name'];

			if (array_key_exists($id, $by_id)) {
				if ($def['mode'] === Types::MODE_API) {
					$same = self::fingerprint($type, $snap_objects[$id]['data'])
						=== self::fingerprint($type, $by_id[$id]);
					$states[$id] = ['state' => $same ? self::STATE_SAME : self::STATE_CHANGED, 'live_id' => $id];
				}
				else {
					$states[$id] = ['state' => self::STATE_PRESENT, 'live_id' => $id];
				}
			}
			elseif (array_key_exists($name, $by_name)) {
				$states[$id] = ['state' => self::STATE_RECREATED, 'live_id' => $by_name[$name]];
			}
			else {
				$states[$id] = ['state' => self::STATE_MISSING, 'live_id' => null];
			}
		}

		return $states;
	}

	/**
	 * Current live version of a snapshot object, in the same shape as the snapshot (for diffing).
	 */
	public function fetchLive(string $type, string $id, ?string $name = null): ?array {
		$def = Types::get($type);

		if ($def['mode'] === Types::MODE_SINGLETON) {
			return $this->api->call($def['service'].'.get', $def['get']);
		}

		$live_id = $this->findLiveId($type, $id, $name);

		if ($live_id === null) {
			return null;
		}

		if ($def['mode'] === Types::MODE_API) {
			$rows = $this->api->call($def['service'].'.get', $def['get'] + [$def['id'].'s' => [$live_id]]);

			return $rows ? $rows[0] : null;
		}

		$source = $this->api->call('configuration.export', [
			'format' => 'json',
			'prettyprint' => false,
			'options' => [$def['export_key'] => [$live_id]]
		]);

		return json_decode($source, true);
	}

	public function findLiveId(string $type, string $id, ?string $name = null): ?string {
		$def = Types::get($type);

		if ($def['mode'] === Types::MODE_SINGLETON) {
			return '0';
		}

		$base = $def['mode'] === Types::MODE_API ? $def['get'] : $def['list'];
		$base['output'] = [$def['id']];
		unset($base['selectPages'], $base['selectUsers'], $base['selectUserGroups']);
		$base = array_filter($base, static fn($k) => strpos($k, 'select') !== 0, ARRAY_FILTER_USE_KEY);

		$rows = $this->api->call($def['service'].'.get', $base + [$def['id'].'s' => [$id]]);

		if ($rows) {
			return (string) $rows[0][$def['id']];
		}

		if ($name !== null && $name !== '') {
			$field = $def['tech'] ?? $def['name'];
			$filter = ($base['filter'] ?? []) + [$field => $name];
			$rows = $this->api->call($def['service'].'.get', ['filter' => $filter] + $base);

			if ($rows) {
				return (string) $rows[0][$def['id']];
			}
		}

		return null;
	}

	/**
	 * Canonical JSON used for "changed?" checks and diffs: no IDs or runtime state, keys sorted, lists of
	 * objects in a stable order, everything stringified.
	 */
	public static function canonical(string $type, ?array $data): string {
		if ($data === null) {
			return '';
		}

		$def = Types::get($type);

		if ($def['mode'] === Types::MODE_EXPORT) {
			unset($data['zabbix_export']['date']);
			$data = self::sortDeep($data, false);
		}
		else {
			$data = self::sortDeep(self::prepare($type, $data, true), true);
		}

		return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			| JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	public static function fingerprint(string $type, ?array $data): string {
		return md5(self::canonical($type, $data));
	}

	private static function sortDeep($value, bool $sort_lists) {
		if (!is_array($value)) {
			return is_bool($value) || is_int($value) || is_float($value) ? (string) $value : $value;
		}

		foreach ($value as $k => $v) {
			$value[$k] = self::sortDeep($v, $sort_lists);
		}

		if (array_is_list($value)) {
			if ($sort_lists) {
				usort($value, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
			}
		}
		else {
			ksort($value, SORT_STRING);
		}

		return $value;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------
	 * Restore
	 * ---------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Restore several objects in dependency order. Each item: ['type' => .., 'id' => ..].
	 *
	 * @return array  list of results, see restore()
	 */
	public function restoreMany(array $items, string $mode): array {
		$ranks = array_column(Types::all(), 'rank', 'key');

		usort($items, static fn(array $a, array $b): int =>
			[$ranks[$a['type']], $a['id']] <=> [$ranks[$b['type']], $b['id']]
		);

		$this->pending = [];
		foreach ($items as $item) {
			$this->pending[$item['type']][$item['id']] = true;
		}

		$results = [];

		foreach ($items as $item) {
			unset($this->pending[$item['type']][$item['id']]);
			$results[] = $this->restore($item['type'], $item['id'], $mode);
		}

		$this->pending = [];

		return $results;
	}

	/**
	 * @return array  ['ok' => bool, 'skipped' => bool, 'type', 'id', 'name', 'message', 'new_id', 'notes' => []]
	 */
	public function restore(string $type, string $id, string $mode, ?string $new_name = null): array {
		$def = Types::get($type);
		$object = $this->store->getObject($this->snapshotid, $type, $id);
		$new_name = $new_name !== null ? trim($new_name) : null;
		$new_name = $new_name === '' ? null : $new_name;

		$result = [
			'ok' => false,
			'skipped' => false,
			'type' => $type,
			'id' => $id,
			'name' => $object['name'] ?? $id,
			'message' => '',
			'new_id' => null,
			'notes' => []
		];

		if ($object === null) {
			$result['message'] = 'Object not found in snapshot.';

			return $result;
		}

		$lookup_name = $this->lookupName($type, $object);

		try {
			if ($mode === self::MODE_AUTO_PARTS) {
				$mode = $def['mode'] === Types::MODE_EXPORT ? self::MODE_MISSING : self::MODE_AUTO;
			}

			if ($mode === self::MODE_AUTO) {
				if ($this->findLiveId($type, $id, $lookup_name) !== null) {
					$result['ok'] = true;
					$result['skipped'] = true;
					$result['message'] = 'Exists, skipped.';

					return $result;
				}

				$mode = $def['mode'] === Types::MODE_EXPORT ? self::MODE_MISSING : self::MODE_CREATE;
			}

			if (!in_array($mode, self::modesFor($type), true)) {
				throw new \RuntimeException(sprintf('Mode "%s" is not supported for %s.', $mode, $def['label']));
			}

			switch ($def['mode']) {
				case Types::MODE_SINGLETON:
					$this->callHealing($def['service'].'.'.$def['update'], self::prepare($type, $object['data'], true),
						true
					);
					$result['message'] = 'Settings restored.';
					break;

				case Types::MODE_EXPORT:
					$this->restoreExport($type, $object, $mode, $new_name, $result);
					break;

				default:
					$this->restoreApi($type, $object, $mode, $new_name, $result);
			}

			$result['ok'] = true;
		}
		catch (ApiException|\RuntimeException $e) {
			$result['message'] = $e->getMessage();
		}

		if ($def['secrets'] !== null && $result['ok']) {
			$result['notes'][] = $def['secrets'];
		}

		if ($result['ok'] && $result['new_id'] !== null && $result['new_id'] !== $id) {
			$this->remap[$type][$id] = $result['new_id'];
			$this->store->saveRemap($this->snapshotid, $this->remap);
		}

		// A restore can change what exists; don't trust cached lookups across objects.
		$this->exists_cache = [];

		return $result;
	}

	private array $exists_cache = [];

	// Objects still queued in the current restoreMany() run; links to them come back when they do.
	private array $pending = [];

	/**
	 * Where should a reference to $ref_type #$old_id point now?
	 *  1. it was recreated from this snapshot before: the new ID (remap.json)
	 *  2. the old ID still exists: keep it
	 *  3. something with the same name exists now: that (e.g. recreated by hand, or by another snapshot)
	 *  4. otherwise keep the old ID and let the API say what's missing
	 */
	public function resolveRef(string $ref_type, string $old_id): string {
		$key = $ref_type.':'.$old_id;

		if (array_key_exists($key, $this->exists_cache)) {
			return $this->exists_cache[$key];
		}

		// Recreated from this snapshot earlier, and still there?
		if (isset($this->remap[$ref_type][$old_id])) {
			$new_id = (string) $this->remap[$ref_type][$old_id];

			try {
				if ($this->findLiveId($ref_type, $new_id) !== null) {
					return $this->exists_cache[$key] = $new_id;
				}
			}
			catch (ApiException $e) {
				return $new_id;
			}
		}

		if (!array_key_exists($key, $this->exists_cache)) {
			$resolved = $old_id;

			try {
				$name = null;
				foreach ($this->store->getIndex($this->snapshotid, $ref_type) as $entry) {
					if ($entry['id'] === $old_id) {
						$name = $entry['tech'] ?? $entry['name'];
						break;
					}
				}

				$live = $this->findLiveId($ref_type, $old_id, $name);

				if ($live !== null) {
					$resolved = $live;
				}
			}
			catch (ApiException $e) {
				// Keep the old ID; the create/update call will report it.
			}

			$this->exists_cache[$key] = $resolved;
		}

		return $this->exists_cache[$key];
	}

	private function lookupName(string $type, array $object): string {
		$def = Types::get($type);

		if ($def['mode'] === Types::MODE_EXPORT && $def['tech'] !== null) {
			return (string) ($object['data']['zabbix_export'][$def['section_key']][0][$def['export_tech']] ?? $object['name']);
		}

		return (string) $object['name'];
	}

	private function restoreApi(string $type, array $object, string $mode, ?string $new_name, array &$result): void {
		$def = Types::get($type);
		$data = Remapper::apply($type, $object['data'], [$this, 'resolveRef']);

		if ($mode === self::MODE_OVERWRITE) {
			$target = $this->findLiveId($type, $object['id'], $new_name ?? $object['name']);

			if ($target === null) {
				throw new \RuntimeException('Nothing to overwrite: the object no longer exists. Recreate it instead.');
			}

			$payload = self::prepare($type, $data, true);

			if ($new_name !== null) {
				$payload[$def['name']] = $new_name;
			}

			$payload[$def['id']] = $target;
			$result['notes'] = array_merge($result['notes'], $this->dropDanglingRefs($type, $payload));
			$this->callHealing($def['service'].'.'.$def['update'], $payload);

			$result['new_id'] = $target;
			$result['message'] = $target === $object['id']
				? 'Overwritten with the snapshot version.'
				: sprintf('Overwrote the recreated object (ID %s) with the snapshot version.', $target);

			return;
		}

		$payload = self::prepare($type, $data, false);
		$result['notes'] = array_merge($result['notes'], $this->dropDanglingRefs($type, $payload));

		if ($new_name !== null) {
			$payload[$def['name']] = $new_name;
			unset($payload['uuid']);
		}

		if ($type === 'user') {
			$password = self::randomPassword();
			$payload['passwd'] = $password;
			$result['notes'][] = sprintf('Temporary password for "%s": %s (change it now).',
				$payload['username'], $password
			);
		}

		try {
			$response = $this->callHealing($def['service'].'.'.$def['create'], $payload);
		}
		catch (ApiException $e) {
			// Users authenticating against LDAP/SAML groups reject a password.
			if ($type === 'user' && stripos($e->getMessage(), 'passw') !== false) {
				unset($payload['passwd']);
				array_pop($result['notes']);
				$response = $this->callHealing($def['service'].'.'.$def['create'], $payload);
			}
			else {
				throw $e;
			}
		}

		$ids = reset($response);
		$result['new_id'] = (string) $ids[0];
		$result['message'] = $new_name !== null
			? sprintf('Created as "%s" (ID %s).', $new_name, $result['new_id'])
			: sprintf('Recreated (new ID %s).', $result['new_id']);
	}

	private function restoreExport(string $type, array $object, string $mode, ?string $new_name, array &$result): void {
		$def = Types::get($type);
		$doc = $object['data'];

		if ($new_name !== null) {
			$doc = self::renameExport($type, $doc, $new_name);
		}

		$import_rules = self::importRules($type, $mode);
		$existed = $new_name === null
			&& $this->findLiveId($type, $object['id'], $this->lookupName($type, $object)) !== null;

		$this->api->call('configuration.import', [
			'format' => 'json',
			'rules' => $import_rules,
			'source' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		]);

		$entry = $doc['zabbix_export'][$def['section_key']][0];
		$match = (string) $entry[$def['export_tech'] ?? $def['name']];

		$result['new_id'] = $this->findLiveId($type, '0', $match);
		$result['name'] = (string) ($entry['name'] ?? $match);

		$labels = [
			self::MODE_MISSING => 'Restored missing parts; existing configuration left as is.',
			self::MODE_CREATE => 'Restored.',
			self::MODE_OVERWRITE => 'Overwritten with the snapshot version (nothing deleted).',
			self::MODE_EXACT => 'Rolled back to the snapshot version, including removal of parts added since.'
		];
		if ($new_name !== null) {
			$result['message'] = sprintf('Imported as "%s" (ID %s).', $new_name, $result['new_id']);
		}
		elseif (!$existed) {
			$result['message'] = sprintf('Recreated (new ID %s).', $result['new_id']);
		}
		else {
			$result['message'] = $labels[$mode];
		}
	}

	/**
	 * Put back chosen pieces of a template or host (see Parts). The template/host must still exist; if it is
	 * gone, restore it as a whole instead.
	 *
	 * @param array $keys  part keys from Parts::index()
	 *
	 * @return array  ['ok', 'message', 'notes' => [], 'restored' => [part labels]]
	 */
	public function restoreParts(string $type, string $id, array $keys, bool $overwrite): array {
		$def = Types::get($type);
		$object = $this->store->getObject($this->snapshotid, $type, $id);
		$result = ['ok' => false, 'message' => '', 'notes' => [], 'restored' => []];

		if ($object === null || !in_array($type, ['template', 'host'], true)) {
			$result['message'] = 'Object not found in snapshot.';

			return $result;
		}

		try {
			$live_id = $this->findLiveId($type, $id, $this->lookupName($type, $object));

			if ($live_id === null) {
				throw new \RuntimeException(sprintf('The %s no longer exists. Restore it as a whole first.', strtolower($def['label'])));
			}

			$live = $this->fetchLive($type, $live_id);
			$live_parts = $live !== null ? Parts::index($live, $type) : [];
			$snap_parts = Parts::index($object['data'], $type);

			[$doc, $macros, $deps] = Parts::build($object['data'], $type, $keys, array_keys($live_parts));

			if ($doc !== null) {
				$this->api->call('configuration.import', [
					'format' => 'json',
					'rules' => Parts::importRules($type, $overwrite),
					'source' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				]);
			}

			foreach ($keys as $key) {
				if (isset($snap_parts[$key]) && $snap_parts[$key]['kind'] !== 'macro') {
					$result['restored'][] = Parts::KINDS[$snap_parts[$key]['kind']].' "'.$snap_parts[$key]['name'].'"';
				}
			}

			foreach ($deps as $key) {
				$result['notes'][] = sprintf('Also recreated %s "%s", which it needs.',
					strtolower(Parts::KINDS[$snap_parts[$key]['kind']]), $snap_parts[$key]['name']
				);
			}

			// Deleting a trigger also removed other triggers' dependencies on it. Put those links back.
			$restored_triggers = array_filter(array_merge($keys, $deps), static fn($k) => strpos($k, 'trigger:') === 0
				&& !isset($live_parts[$k])
			);

			foreach ($restored_triggers as $tkey) {
				foreach (Parts::dependents($snap_parts, $tkey) as $dkey) {
					if (!isset($live_parts[$dkey]) || in_array($dkey, $keys, true)) {
						continue;
					}

					$ids = $this->triggerIds($live_id, [$tkey, $dkey]);

					if (isset($ids[$tkey], $ids[$dkey])) {
						try {
							$current = $this->api->call('trigger.get', [
								'output' => ['triggerid'],
								'triggerids' => [$ids[$dkey]],
								'selectDependencies' => ['triggerid']
							]);
							$depends = array_column($current[0]['dependencies'] ?? [], 'triggerid');

							if (in_array($ids[$tkey], $depends, true)) {
								continue;
							}

							$depends[] = $ids[$tkey];
							$this->api->call('trigger.update', [
								'triggerid' => $ids[$dkey],
								'dependencies' => array_map(static fn($id) => ['triggerid' => $id], $depends)
							]);
							$result['notes'][] = sprintf('Relinked: "%s" depends on it again.', $snap_parts[$dkey]['name']);
						}
						catch (ApiException $e) {
							$result['notes'][] = sprintf('Could not relink "%s": %s', $snap_parts[$dkey]['name'], $e->getMessage());
						}
					}
				}
			}

			// Zabbix strips widgets from template dashboards when what they show is deleted. Point those out.
			$needles = [];
			foreach (array_merge($keys, $deps) as $key) {
				$part = $snap_parts[$key] ?? null;

				if ($part === null) {
					continue;
				}

				if ($part['kind'] === 'item') {
					$needles[] = '"key":'.json_encode($part['data']['key'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				}
				elseif ($part['kind'] === 'graph') {
					$needles[] = '"name":'.json_encode($part['data']['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				}
				elseif ($part['kind'] === 'lld') {
					foreach ($part['data']['item_prototypes'] ?? [] as $proto) {
						$needles[] = '"key":'.json_encode($proto['key'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
					}
					foreach ($part['data']['graph_prototypes'] ?? [] as $proto) {
						$needles[] = '"name":'.json_encode($proto['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
					}
				}
			}

			if ($needles && $live !== null) {
				$states = Parts::compare($snap_parts, $live_parts);

				foreach ($snap_parts as $key => $part) {
					if ($part['kind'] !== 'dashboard' || ($states[$key] ?? '') !== 'changed' || in_array($key, $keys, true)) {
						continue;
					}

					$json = json_encode($part['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

					foreach ($needles as $needle) {
						if (strpos($json, $needle) !== false) {
							$result['notes'][] = sprintf('Template dashboard "%s" lost widgets that showed this when it was deleted. Restore that dashboard with overwrite to put them back.', $part['name']);
							break;
						}
					}
				}
			}

			foreach ($macros as $macro) {
				$payload = Parts::macroPayload($macro);
				$existing = $this->api->call('usermacro.get', [
					'output' => ['hostmacroid'],
					'hostids' => [$live_id],
					'filter' => ['macro' => $payload['macro']]
				]);

				if ($existing && !$overwrite) {
					$result['notes'][] = sprintf('Macro %s exists; left as is (choose overwrite to replace it).', $payload['macro']);
					continue;
				}

				if ($existing) {
					$this->callHealing('usermacro.update', ['hostmacroid' => $existing[0]['hostmacroid']] + $payload);
				}
				else {
					$this->callHealing('usermacro.create', ['hostid' => $live_id] + $payload);
				}

				$result['restored'][] = 'Macro '.$payload['macro'];

				if ($payload['type'] == 1) {
					$result['notes'][] = sprintf('%s is a secret macro; Zabbix never exports its value, so it is empty now.', $payload['macro']);
				}
			}

			$result['ok'] = true;
			$result['message'] = $result['restored']
				? ($overwrite ? 'Overwritten: ' : 'Restored: ').implode(', ', $result['restored'])
				: 'Nothing to do.';
		}
		catch (ApiException|\RuntimeException $e) {
			$result['message'] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Live trigger IDs on a template/host for part keys "trigger:<name>|<expression>".
	 */
	private function triggerIds(string $hostid, array $keys): array {
		$names = [];
		foreach ($keys as $key) {
			$names[] = explode('|', substr($key, 8), 2)[0];
		}

		// Match by name. expandExpression would also expand user macros, so the expression cannot be compared;
		// a name used by more than one trigger on the same template is left alone rather than guessed.
		$rows = $this->api->call('trigger.get', [
			'output' => ['triggerid', 'description'],
			'hostids' => [$hostid],
			'filter' => ['description' => array_values(array_unique($names))],
			'inherited' => false
		]);

		$by_name = [];
		foreach ($rows as $row) {
			$by_name[$row['description']][] = $row['triggerid'];
		}

		$ids = [];
		foreach ($keys as $key) {
			$name = explode('|', substr($key, 8), 2)[0];

			if (count($by_name[$name] ?? []) == 1) {
				$ids[$key] = $by_name[$name][0];
			}
		}

		return $ids;
	}

	public static function importRules(string $type, string $mode): array {
		$create = true;
		$update = in_array($mode, [self::MODE_OVERWRITE, self::MODE_EXACT], true);
		$delete = $mode === self::MODE_EXACT;

		$cud = ['createMissing' => $create, 'updateExisting' => $update, 'deleteMissing' => $delete];
		$cu = ['createMissing' => $create, 'updateExisting' => $update];

		$rules = [
			// Groups are only ever created, never modified: a host import shouldn't rename a group.
			'host_groups' => ['createMissing' => true, 'updateExisting' => false],
			'template_groups' => ['createMissing' => true, 'updateExisting' => false]
		];

		switch ($type) {
			case 'template':
			case 'host':
				$rules += [
					$type.'s' => $cu,
					'templateLinkage' => ['createMissing' => true, 'deleteMissing' => $delete],
					'items' => $cud,
					'discoveryRules' => $cud,
					'triggers' => $cud,
					'graphs' => $cud,
					'httptests' => $cud,
					'valueMaps' => $cud
				];

				if ($type === 'template') {
					$rules['templateDashboards'] = $cud;
				}
				break;

			case 'mediatype':
				$rules['mediaTypes'] = $cu;
				break;

			case 'image':
				$rules['images'] = $cu;
				break;

			case 'map':
				$rules['maps'] = $cu;
				break;
		}

		return $rules;
	}

	/**
	 * Rename a template/host/... inside an export so it imports as a separate copy.
	 */
	public static function renameExport(string $type, array $doc, string $new_name): array {
		$def = Types::get($type);
		$section = $def['section_key'];
		$entry = &$doc['zabbix_export'][$section][0];

		if ($def['export_tech'] !== null) {
			$old_tech = (string) $entry[$def['export_tech']];
			$entry[$def['export_tech']] = $new_name;
			$entry['name'] = $new_name;

			// Expressions and graph/dashboard references point at "/Old name/key" or {host: "Old name"}.
			$fix = static function ($value, $key = null) use (&$fix, $old_tech, $new_name) {
				if (is_array($value)) {
					foreach ($value as $k => $v) {
						$value[$k] = $fix($v, $k);
					}

					return $value;
				}

				if (!is_string($value)) {
					return $value;
				}

				if ($key === 'host' && $value === $old_tech) {
					return $new_name;
				}

				return str_replace('/'.$old_tech.'/', '/'.$new_name.'/', $value);
			};

			foreach ([$section, 'triggers', 'graphs'] as $key) {
				if (array_key_exists($key, $doc['zabbix_export'])) {
					$doc['zabbix_export'][$key] = $fix($doc['zabbix_export'][$key]);
				}
			}
		}
		else {
			$entry['name'] = $new_name;
		}

		unset($entry);

		// New UUIDs, otherwise the import matches the original object instead of creating a copy.
		foreach ([$section, 'triggers', 'graphs'] as $key) {
			if (array_key_exists($key, $doc['zabbix_export'])) {
				$doc['zabbix_export'][$key] = self::newUuids($doc['zabbix_export'][$key]);
			}
		}

		return $doc;
	}

	private static function newUuids(array $data): array {
		foreach ($data as $k => $v) {
			if ($k === 'uuid' && is_string($v)) {
				$data[$k] = self::uuid4();
			}
			elseif (is_array($v)) {
				$data[$k] = self::newUuids($v);
			}
		}

		return $data;
	}

	/**
	 * Turns a stored object into an API create/update payload.
	 */
	public static function prepare(string $type, array $data, bool $for_update): array {
		$def = Types::get($type);

		foreach ($def['strip_top'] as $key) {
			unset($data[$key]);
		}

		if ($def['strip_deep']) {
			$data = self::stripDeep($data, array_flip($def['strip_deep']));
		}

		if ($for_update) {
			foreach ($def['create_only'] as $key) {
				unset($data[$key]);
			}
		}

		switch ($type) {
			case 'action':
			case 'correlation':
				if (isset($data['filter'])) {
					if ((int) $data['filter']['evaltype'] != 3) {
						unset($data['filter']['formula']);

						foreach ($data['filter']['conditions'] as &$condition) {
							unset($condition['formulaid']);
						}
						unset($condition);
					}
				}
				break;

			case 'maintenance':
				$data['groups'] = $data['hostgroups'] ?? [];
				unset($data['hostgroups']);

				$keep = [
					0 => ['start_date', 'period'],
					2 => ['start_time', 'period', 'every'],
					3 => ['start_time', 'period', 'every', 'dayofweek'],
					4 => ['start_time', 'period', 'every', 'day', 'dayofweek', 'month']
				];

				foreach ($data['timeperiods'] ?? [] as $i => $period) {
					$fields = $keep[(int) $period['timeperiod_type']] ?? array_keys($period);
					$fields[] = 'timeperiod_type';

					if ((int) $period['timeperiod_type'] == 4) {
						// Monthly: either a day of month or a weekday-in-week, not both.
						$fields = (int) $period['day'] != 0
							? array_diff($fields, ['dayofweek', 'every'])
							: array_diff($fields, ['day']);
					}

					$data['timeperiods'][$i] = array_intersect_key($period, array_flip($fields));
				}
				break;

			case 'proxy':
				if ((int) ($data['custom_timeouts'] ?? 0) == 0) {
					foreach (array_keys($data) as $key) {
						if (strpos($key, 'timeout_') === 0) {
							unset($data[$key]);
						}
					}
				}

				// PSK cannot be restored (write-only), so don't ask for PSK encryption without it.
				foreach (['tls_connect', 'tls_accept'] as $key) {
					if (isset($data[$key]) && ((int) $data[$key] & 2)) {
						$data[$key] = (string) (((int) $data[$key] & ~2) ?: 1);
					}
				}
				break;

			case 'usermacro':
				// Secret macros come back without a value; keep the macro and its type, empty value.
				if ((int) ($data['type'] ?? 0) == 1 && !array_key_exists('value', $data)) {
					$data['value'] = '';
				}
				break;
		}

		return $data;
	}

	private static function stripDeep(array $data, array $keys): array {
		foreach ($data as $k => $v) {
			if (is_string($k) && array_key_exists($k, $keys)) {
				unset($data[$k]);
			}
			elseif (is_array($v)) {
				$data[$k] = self::stripDeep($v, $keys);
			}
		}

		return $data;
	}

	/**
	 * Zabbix validates create/update strictly: a field that get returns but that doesn't apply to this particular
	 * object (esc_period on a discovery action, value2 on a non-tag condition, exp_delimiter on a non-list regexp)
	 * is rejected. Rather than mirror every rule for every Zabbix version, drop exactly the field the API names
	 * and try again. Only "unexpected parameter" and "value must be empty" are handled, both of which mean the
	 * field carries no meaning for this object.
	 */
	private function callHealing(string $method, array $payload, bool $single = false) {
		for ($attempt = 0; $attempt < 60; $attempt++) {
			try {
				return $this->api->call($method, $payload);
			}
			catch (ApiException $e) {
				$path = self::offendingPath($e->getMessage(), $single);

				if ($path === null || !self::unsetPath($payload, $path)) {
					throw $e;
				}
			}
		}

		throw new ApiException($method.': giving up after too many validation retries.');
	}

	/**
	 * @return array|null  path inside the payload, e.g. ['filter', 'conditions', 0, 'value2']
	 */
	private static function offendingPath(string $message, bool $single): ?array {
		if (preg_match('~Invalid parameter "(/[^"]*)": unexpected parameter "([^"]+)"~', $message, $m)) {
			$segments = array_values(array_filter(explode('/', $m[1]), 'strlen'));
			$segments[] = $m[2];
		}
		elseif (preg_match('~Invalid parameter "(/[^"]+)": value must be empty~', $message, $m)) {
			$segments = array_values(array_filter(explode('/', $m[1]), 'strlen'));
		}
		else {
			return null;
		}

		// create/update of one object is validated as a list of one: "/1/...". Singletons are validated as is.
		if (!$single) {
			if (!$segments || $segments[0] !== '1') {
				return null;
			}

			array_shift($segments);
		}

		if (!$segments) {
			return null;
		}

		// Numeric segments are 1-based positions in lists.
		return array_map(static fn($s) => ctype_digit($s) ? (int) $s - 1 : $s, $segments);
	}

	private static function unsetPath(array &$data, array $path): bool {
		$key = array_shift($path);

		if (is_int($key) && array_is_list($data)) {
			if (!array_key_exists($key, $data)) {
				return false;
			}
		}
		elseif (!array_key_exists($key, $data)) {
			return false;
		}

		if (!$path) {
			unset($data[$key]);

			return true;
		}

		if (!is_array($data[$key])) {
			return false;
		}

		return self::unsetPath($data[$key], $path);
	}

	/**
	 * Drop references to objects that no longer exist where the reference is optional (sharing, membership,
	 * service tree links), so the object itself can come back. Returns notes about what was dropped.
	 */
	private function dropDanglingRefs(string $type, array &$payload): array {
		$checks = [
			'dashboard' => [['users', 'userid', 'user'], ['userGroups', 'usrgrpid', 'usergroup']],
			'usergroup' => [['users', 'userid', 'user']],
			'service' => [['parents', 'serviceid', 'service'], ['children', 'serviceid', 'service']],
			'report' => [['users', 'userid', 'user'], ['user_groups', 'usrgrpid', 'usergroup']]
		];

		$notes = [];

		foreach ($checks[$type] ?? [] as [$list, $field, $ref_type]) {
			if (empty($payload[$list])) {
				continue;
			}

			$ref = Types::get($ref_type);
			$ids = array_values(array_unique(array_column($payload[$list], $field)));
			$live = $this->api->call($ref['service'].'.get', [
				'output' => [$ref['id']],
				$ref['id'].'s' => $ids,
				'preservekeys' => true
			]);

			$dropped = 0;
			$kept = [];

			foreach ($payload[$list] as $row) {
				if (array_key_exists($row[$field], $live)) {
					$kept[] = $row;
				}
				elseif (!$this->isPendingOldOrNew($ref_type, (string) $row[$field])) {
					$dropped++;
				}
			}

			$payload[$list] = $kept;

			if ($dropped) {
				$notes[] = sprintf('Left out %d link(s) to a %s that no longer exists. Restore it, then overwrite this object to relink.',
					$dropped, strtolower($ref['label'])
				);
			}
		}

		return $notes;
	}

	private function isPendingOldOrNew(string $type, string $id): bool {
		return isset($this->pending[$type][$id]);
	}

	private static function uuid4(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return bin2hex($bytes);
	}

	private static function randomPassword(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
		$password = '';

		for ($i = 0; $i < 16; $i++) {
			$password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}

		// Satisfy the strictest built-in password policy (upper, lower, digit, special).
		return $password.'-'.random_int(10, 99).'!';
	}
}
