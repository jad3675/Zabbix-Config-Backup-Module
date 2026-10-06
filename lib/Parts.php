<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * The pieces inside one template or host export: items, triggers, graphs, discovery rules, web scenarios,
 * value maps, template dashboards and macros. Lets you put back one deleted trigger without touching anything
 * else on the template.
 *
 * Restoring a piece imports a cut-down export holding just that piece plus whatever it cannot exist without
 * (the items a trigger or graph uses, a dependent item's master, a value map an item uses, triggers it depends
 * on). Those extras are only included when they are missing from the live system, so they are recreated if
 * needed but never overwritten. Macros go through usermacro.* because an import would replace the whole macro
 * list.
 */
class Parts {

	public const KINDS = [
		'item' => 'Item',
		'trigger' => 'Trigger',
		'graph' => 'Graph',
		'lld' => 'Discovery rule',
		'web' => 'Web scenario',
		'valuemap' => 'Value map',
		'dashboard' => 'Dashboard',
		'macro' => 'Macro'
	];

	private const MACRO_TYPES = ['TEXT' => 0, 'SECRET_TEXT' => 1, 'VAULT' => 2];

	/**
	 * @return array  key => ['key', 'kind', 'name', 'detail', 'data', 'parent' => item key for nested triggers]
	 */
	public static function index(array $doc, string $type): array {
		$def = Types::get($type);
		$export = $doc['zabbix_export'] ?? [];
		$entry = $export[$def['section_key']][0] ?? [];
		$host = (string) ($entry[$def['export_tech']] ?? '');
		$parts = [];

		foreach ($entry['items'] ?? [] as $item) {
			$parts['item:'.$item['key']] = ['kind' => 'item', 'name' => $item['name'], 'detail' => $item['key'],
				'data' => $item
			];

			foreach ($item['triggers'] ?? [] as $trigger) {
				$parts[self::triggerKey($trigger)] = ['kind' => 'trigger', 'name' => $trigger['name'],
					'detail' => $trigger['expression'], 'data' => $trigger, 'parent' => $item['key']
				];
			}
		}

		foreach ($export['triggers'] ?? [] as $trigger) {
			if (self::mentionsHost($trigger['expression'].' '.($trigger['recovery_expression'] ?? ''), $host)) {
				$parts[self::triggerKey($trigger)] = ['kind' => 'trigger', 'name' => $trigger['name'],
					'detail' => $trigger['expression'], 'data' => $trigger, 'parent' => null
				];
			}
		}

		foreach ($export['graphs'] ?? [] as $graph) {
			$hosts = array_unique(array_map(static fn($gi) => $gi['item']['host'] ?? '', $graph['graph_items'] ?? []));

			if (in_array($host, $hosts, true)) {
				$parts['graph:'.$graph['name']] = ['kind' => 'graph', 'name' => $graph['name'],
					'detail' => sprintf('%d item(s)', count($graph['graph_items'] ?? [])), 'data' => $graph
				];
			}
		}

		foreach ($entry['discovery_rules'] ?? [] as $rule) {
			$parts['lld:'.$rule['key']] = ['kind' => 'lld', 'name' => $rule['name'], 'detail' => $rule['key'],
				'data' => $rule
			];
		}

		foreach ($entry['httptests'] ?? [] as $web) {
			$parts['web:'.$web['name']] = ['kind' => 'web', 'name' => $web['name'],
				'detail' => sprintf('%d step(s)', count($web['steps'] ?? [])), 'data' => $web
			];
		}

		foreach ($entry['valuemaps'] ?? [] as $vm) {
			$parts['valuemap:'.$vm['name']] = ['kind' => 'valuemap', 'name' => $vm['name'],
				'detail' => sprintf('%d mapping(s)', count($vm['mappings'] ?? [])), 'data' => $vm
			];
		}

		foreach ($entry['dashboards'] ?? [] as $dash) {
			$parts['dashboard:'.$dash['name']] = ['kind' => 'dashboard', 'name' => $dash['name'],
				'detail' => sprintf('%d page(s)', count($dash['pages'] ?? [])), 'data' => $dash
			];
		}

		foreach ($entry['macros'] ?? [] as $macro) {
			$parts['macro:'.$macro['macro']] = ['kind' => 'macro', 'name' => $macro['macro'],
				'detail' => ($macro['type'] ?? 'TEXT') === 'SECRET_TEXT' ? '(secret)' : (string) ($macro['value'] ?? ''),
				'data' => $macro
			];
		}

		foreach ($parts as $key => &$part) {
			$part['key'] = $key;
			$part += ['parent' => null];
		}
		unset($part);

		return $parts;
	}

	/**
	 * Triggers in the snapshot that depend on $trigger_key: deleting a trigger drops those links.
	 */
	public static function dependents(array $parts, string $trigger_key): array {
		$out = [];

		foreach ($parts as $key => $part) {
			if ($part['kind'] !== 'trigger') {
				continue;
			}

			foreach ($part['data']['dependencies'] ?? [] as $dep) {
				if ('trigger:'.$dep['name'].'|'.$dep['expression'] === $trigger_key) {
					$out[] = $key;
				}
			}
		}

		return $out;
	}

	public static function triggerKey(array $trigger): string {
		return 'trigger:'.$trigger['name'].'|'.$trigger['expression'];
	}

	/**
	 * Snapshot parts against live parts: missing / changed / same.
	 */
	public static function compare(array $snap, array $live): array {
		$states = [];

		foreach ($snap as $key => $part) {
			if (!array_key_exists($key, $live)) {
				$states[$key] = 'missing';
			}
			else {
				$states[$key] = self::fingerprint($part['data']) === self::fingerprint($live[$key]['data'])
					? 'same' : 'changed';
			}
		}

		return $states;
	}

	private static function fingerprint(array $data): string {
		// Nested triggers are parts of their own.
		unset($data['triggers']);

		$sort = static function ($v) use (&$sort) {
			if (!is_array($v)) {
				return (string) $v;
			}

			$v = array_map($sort, $v);

			if (!array_is_list($v)) {
				ksort($v, SORT_STRING);
			}

			return $v;
		};

		return md5(json_encode($sort($data)));
	}

	/**
	 * Builds what to import for the chosen parts.
	 *
	 * @param array $live_keys  part keys that exist live: dependencies already there are left out
	 *
	 * @return array  [export doc or null, macros to set, list of dependency part keys added]
	 */
	public static function build(array $doc, string $type, array $selected, array $live_keys): array {
		$def = Types::get($type);
		$export = $doc['zabbix_export'];
		$entry = $export[$def['section_key']][0];
		$host = (string) $entry[$def['export_tech']];
		$parts = self::index($doc, $type);
		$live = array_flip($live_keys);

		$want = [];        // part key => true (chosen)
		$deps = [];        // part key => true (needed by a chosen part, only if missing live)
		$macros = [];

		foreach ($selected as $key) {
			if (!isset($parts[$key])) {
				continue;
			}

			if ($parts[$key]['kind'] === 'macro') {
				$macros[] = $parts[$key]['data'];
			}
			else {
				$want[$key] = true;
			}
		}

		// Work out dependencies until nothing new turns up.
		$queue = array_keys($want);

		while ($queue) {
			$key = array_shift($queue);
			$part = $parts[$key];
			$needs = [];

			switch ($part['kind']) {
				case 'item':
				case 'lld':
					if (isset($part['data']['master_item']['key'])) {
						$needs[] = 'item:'.$part['data']['master_item']['key'];
					}
					if (isset($part['data']['valuemap']['name'])) {
						$needs[] = 'valuemap:'.$part['data']['valuemap']['name'];
					}
					break;

				case 'trigger':
					if ($part['parent'] !== null) {
						$needs[] = 'item:'.$part['parent'];
					}
					foreach (self::itemKeys($part['data']['expression'].' '.($part['data']['recovery_expression'] ?? ''), $host) as $k) {
						$needs[] = 'item:'.$k;
					}
					foreach ($part['data']['dependencies'] ?? [] as $dep) {
						$needs[] = 'trigger:'.$dep['name'].'|'.$dep['expression'];
					}
					break;

				case 'graph':
					foreach ($part['data']['graph_items'] ?? [] as $gi) {
						if (($gi['item']['host'] ?? '') === $host) {
							$needs[] = 'item:'.$gi['item']['key'];
						}
					}
					break;

				case 'dashboard':
					foreach (self::dashboardItemKeys($part['data'], $host) as $k) {
						$needs[] = 'item:'.$k;
					}
					break;
			}

			foreach ($needs as $need) {
				if (isset($parts[$need]) && !isset($want[$need]) && !isset($deps[$need]) && !isset($live[$need])) {
					$deps[$need] = true;
					$queue[] = $need;
				}
			}
		}

		$include = $want + $deps;

		if (!$include) {
			return [null, $macros, []];
		}

		// Cut-down entry: identity only, plus interfaces on hosts so items can find theirs.
		$keep = $type === 'template'
			? ['uuid', 'template', 'name', 'groups']
			: ['host', 'name', 'groups', 'interfaces'];
		$new_entry = array_intersect_key($entry, array_flip($keep));
		$top_triggers = [];
		$graphs = [];

		foreach ($entry['items'] ?? [] as $item) {
			$item_key = 'item:'.$item['key'];
			$nested = [];

			foreach ($item['triggers'] ?? [] as $trigger) {
				$tk = self::triggerKey($trigger);

				// A chosen item brings back its own triggers only if they are missing.
				if (isset($include[$tk]) || (isset($want[$item_key]) && !isset($live[$tk]))) {
					$nested[] = $trigger;
				}
			}

			if (isset($include[$item_key]) || $nested) {
				$item['triggers'] = $nested;

				if (!$nested) {
					unset($item['triggers']);
				}

				$new_entry['items'][] = $item;
			}
		}

		foreach (['lld' => 'discovery_rules', 'web' => 'httptests', 'valuemap' => 'valuemaps',
				'dashboard' => 'dashboards'] as $kind => $field) {
			foreach ($entry[$field] ?? [] as $row) {
				$name = in_array($kind, ['lld'], true) ? $row['key'] : $row['name'];

				if (isset($include[$kind.':'.$name])) {
					$new_entry[$field][] = $row;
				}
			}
		}

		foreach ($export['triggers'] ?? [] as $trigger) {
			if (isset($include[self::triggerKey($trigger)])) {
				$top_triggers[] = $trigger;
			}
		}

		foreach ($export['graphs'] ?? [] as $graph) {
			if (isset($include['graph:'.$graph['name']])) {
				$graphs[] = $graph;
			}
		}

		$out = ['version' => $export['version']];

		foreach (['template_groups', 'host_groups'] as $groups) {
			if (isset($export[$groups])) {
				$out[$groups] = $export[$groups];
			}
		}

		$out[$def['section_key']] = [$new_entry];

		if ($top_triggers) {
			$out['triggers'] = $top_triggers;
		}

		if ($graphs) {
			$out['graphs'] = $graphs;
		}

		return [['zabbix_export' => $out], $macros, array_keys($deps)];
	}

	public static function importRules(string $type, bool $overwrite): array {
		$cud = ['createMissing' => true, 'updateExisting' => $overwrite, 'deleteMissing' => false];

		$rules = [
			'host_groups' => ['createMissing' => true, 'updateExisting' => false],
			'template_groups' => ['createMissing' => true, 'updateExisting' => false],
			// The template/host itself is not touched: no field, link, tag or macro changes.
			$type.'s' => ['createMissing' => false, 'updateExisting' => false],
			'templateLinkage' => ['createMissing' => false, 'deleteMissing' => false],
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

		return $rules;
	}

	public static function macroPayload(array $macro): array {
		$payload = [
			'macro' => $macro['macro'],
			'type' => self::MACRO_TYPES[$macro['type'] ?? 'TEXT'] ?? 0,
			'value' => (string) ($macro['value'] ?? ''),
			'description' => (string) ($macro['description'] ?? '')
		];

		// 7.4+: the template wizard settings of a macro. Export writes enums as words, the API wants numbers.
		if (isset($macro['config']) && is_array($macro['config'])) {
			$config = $macro['config'];
			$enums = [
				'type' => ['NOCONF' => 0, 'TEXT' => 1, 'LIST' => 2, 'CHECKBOX' => 3],
				'required' => ['NO' => 0, 'YES' => 1]
			];

			foreach ($enums as $field => $map) {
				if (isset($config[$field]) && isset($map[$config[$field]])) {
					$config[$field] = $map[$config[$field]];
				}
			}

			$payload['config'] = $config;
		}

		return $payload;
	}

	private static function mentionsHost(string $expression, string $host): bool {
		return strpos($expression, '/'.$host.'/') !== false;
	}

	/**
	 * Item keys used by an expression for this host: /Host/key[a,"b,c"] up to the , or ) that ends it.
	 */
	public static function itemKeys(string $expression, string $host): array {
		$keys = [];
		$needle = '/'.$host.'/';
		$offset = 0;

		while (($pos = strpos($expression, $needle, $offset)) !== false) {
			$i = $pos + strlen($needle);
			$depth = 0;
			$quoted = false;
			$key = '';

			for (; $i < strlen($expression); $i++) {
				$c = $expression[$i];

				if ($quoted) {
					$key .= $c;
					if ($c === '\\' && $i + 1 < strlen($expression)) {
						$key .= $expression[++$i];
					}
					elseif ($c === '"') {
						$quoted = false;
					}
					continue;
				}

				if ($c === '"' && $depth > 0) {
					$quoted = true;
				}
				elseif ($c === '[') {
					$depth++;
				}
				elseif ($c === ']') {
					$depth--;
				}
				elseif ($depth == 0 && ($c === ',' || $c === ')')) {
					break;
				}

				$key .= $c;
			}

			$keys[] = $key;
			$offset = $i;
		}

		return array_values(array_unique($keys));
	}

	private static function dashboardItemKeys(array $dashboard, string $host): array {
		$keys = [];

		$walk = static function ($v) use (&$walk, &$keys, $host) {
			if (!is_array($v)) {
				return;
			}

			if (isset($v['host'], $v['key']) && $v['host'] === $host && is_string($v['key'])) {
				$keys[] = $v['key'];
			}

			foreach ($v as $child) {
				$walk($child);
			}
		};
		$walk($dashboard);

		return array_values(array_unique($keys));
	}
}
