<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Rewrites references inside a snapshot object before it is restored.
 *
 * When an object is recreated it gets a new ID, so anything restored afterwards that still points at the old
 * ID (an action at a user group, a dashboard widget at a host group, a report at a dashboard) has to follow it.
 * The resolver callback decides: fn(string $ref_type, string $old_id): string $id_to_use.
 */
class Remapper {

	// Action condition types whose "value" is an object ID.
	private const CONDITION_TYPES = [
		0 => 'hostgroup',
		1 => 'host',
		13 => 'template',
		18 => 'drule',
		20 => 'proxy'
	];

	// Dashboard widget field types that hold object IDs.
	private const WIDGET_FIELD_TYPES = [
		2 => 'hostgroup',
		3 => 'host',
		8 => 'map',
		9 => 'service',
		10 => 'sla',
		11 => 'user',
		12 => 'action',
		13 => 'mediatype'
	];

	public static function apply(string $type, array $data, callable $resolve): array {
		$def = Types::get($type);

		if ($def['refs']) {
			$data = self::walk($data, $def['refs'], $resolve);
		}

		switch ($type) {
			case 'action':
				foreach ($data['filter']['conditions'] ?? [] as $i => $condition) {
					$target = self::CONDITION_TYPES[(int) ($condition['conditiontype'] ?? -1)] ?? null;

					if ($target !== null && ctype_digit((string) $condition['value'])) {
						$data['filter']['conditions'][$i]['value'] = $resolve($target, (string) $condition['value']);
					}
				}
				break;

			case 'dashboard':
				foreach ($data['pages'] ?? [] as $p => $page) {
					foreach ($page['widgets'] ?? [] as $w => $widget) {
						foreach ($widget['fields'] ?? [] as $f => $field) {
							$target = self::WIDGET_FIELD_TYPES[(int) $field['type']] ?? null;

							if ($target !== null) {
								$data['pages'][$p]['widgets'][$w]['fields'][$f]['value'] =
									$resolve($target, (string) $field['value']);
							}
						}
					}
				}
				break;

			case 'usergroup':
				foreach (['hostgroup_rights' => 'hostgroup', 'templategroup_rights' => 'templategroup'] as $key => $target) {
					foreach ($data[$key] ?? [] as $i => $right) {
						$data[$key][$i]['id'] = $resolve($target, (string) $right['id']);
					}
				}

				foreach ($data['tag_filters'] ?? [] as $i => $filter) {
					$data['tag_filters'][$i]['groupid'] = $resolve('hostgroup', (string) $filter['groupid']);
				}

				foreach ($data['users'] ?? [] as $i => $user) {
					$data['users'][$i]['userid'] = $resolve('user', (string) $user['userid']);
				}
				break;
		}

		return $data;
	}

	private static function walk(array $data, array $refs, callable $resolve): array {
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = self::walk($value, $refs, $resolve);
			}
			elseif (is_string($key) && array_key_exists($key, $refs) && is_scalar($value) && (string) $value !== ''
					&& (string) $value !== '0') {
				$data[$key] = $resolve($refs[$key], (string) $value);
			}
		}

		return $data;
	}
}
