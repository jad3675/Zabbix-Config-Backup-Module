<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Everything the module knows about each configuration object type.
 *
 * mode:
 *   api       - read with <service>.get, restored with <service>.create / .update
 *   export    - read with configuration.export, restored with configuration.import (templates, hosts, ...)
 *   singleton - one global object, restored with <service>.update (settings, housekeeping)
 *
 * strip_top:  keys removed from the top level before create/update (ids, read-only, runtime state)
 * strip_deep: keys removed at any depth (child object ids)
 * rank:       restore order when several objects are restored together (dependencies first)
 * refs:       which reference keys inside the object point to which type (for ID remapping)
 * secrets:    what the API never returns, so cannot come back from a backup
 */
class Types {

	public const MODE_API = 'api';
	public const MODE_EXPORT = 'export';
	public const MODE_SINGLETON = 'singleton';

	private static ?array $types = null;

	public static function all(): array {
		if (self::$types !== null) {
			return self::$types;
		}

		$types = [
			'hostgroup' => [
				'label' => 'Host group',
				'section' => 'Data collection',
				'mode' => self::MODE_API,
				'service' => 'hostgroup',
				'id' => 'groupid',
				'name' => 'name',
				'get' => ['output' => ['groupid', 'name', 'uuid', 'flags'], 'filter' => ['flags' => 0]],
				'strip_top' => ['groupid', 'flags'],
				'rank' => 10
			],
			'templategroup' => [
				'label' => 'Template group',
				'section' => 'Data collection',
				'mode' => self::MODE_API,
				'service' => 'templategroup',
				'id' => 'groupid',
				'name' => 'name',
				'get' => ['output' => ['groupid', 'name', 'uuid']],
				'strip_top' => ['groupid'],
				'rank' => 10
			],
			'mediatype' => [
				'label' => 'Media type',
				'section' => 'Alerts',
				'mode' => self::MODE_EXPORT,
				'service' => 'mediatype',
				'id' => 'mediatypeid',
				'name' => 'name',
				'list' => ['output' => ['mediatypeid', 'name']],
				'export_key' => 'mediaTypes',
				'section_key' => 'media_types',
				'rank' => 15,
				'secrets' => 'Passwords of SMTP/SMS/webhook media types are not exported by Zabbix and must be re-entered.'
			],
			'image' => [
				'label' => 'Image',
				'section' => 'Administration',
				'mode' => self::MODE_EXPORT,
				'service' => 'image',
				'id' => 'imageid',
				'name' => 'name',
				'list' => ['output' => ['imageid', 'name']],
				'export_key' => 'images',
				'section_key' => 'images',
				'rank' => 15
			],
			'proxygroup' => [
				'label' => 'Proxy group',
				'section' => 'Administration',
				'mode' => self::MODE_API,
				'service' => 'proxygroup',
				'id' => 'proxy_groupid',
				'name' => 'name',
				'get' => ['output' => 'extend'],
				'strip_top' => ['proxy_groupid', 'state'],
				'rank' => 16
			],
			'proxy' => [
				'label' => 'Proxy',
				'section' => 'Administration',
				'mode' => self::MODE_API,
				'service' => 'proxy',
				'id' => 'proxyid',
				'name' => 'name',
				'get' => ['output' => 'extend'],
				'strip_top' => ['proxyid', 'lastaccess', 'version', 'compatibility', 'state'],
				'refs' => ['proxy_groupid' => 'proxygroup'],
				'rank' => 17,
				'secrets' => 'PSK identity and PSK are write-only in Zabbix. A PSK-encrypted proxy is restored without encryption and must be re-keyed.'
			],
			'template' => [
				'label' => 'Template',
				'section' => 'Data collection',
				'mode' => self::MODE_EXPORT,
				'service' => 'template',
				'id' => 'templateid',
				'name' => 'name',
				'tech' => 'host',
				'export_tech' => 'template',
				'list' => ['output' => ['templateid', 'host', 'name']],
				'export_key' => 'templates',
				'section_key' => 'templates',
				'rank' => 20
			],
			'host' => [
				'label' => 'Host',
				'section' => 'Data collection',
				'mode' => self::MODE_EXPORT,
				'service' => 'host',
				'id' => 'hostid',
				'name' => 'name',
				'tech' => 'host',
				'list' => ['output' => ['hostid', 'host', 'name'], 'filter' => ['flags' => 0]],
				'export_key' => 'hosts',
				'section_key' => 'hosts',
				'rank' => 30,
				'secrets' => 'Secret macro values and PSK keys are never exported by Zabbix.'
			],
			'role' => [
				'label' => 'User role',
				'section' => 'Users',
				'mode' => self::MODE_API,
				'service' => 'role',
				'id' => 'roleid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectRules' => 'extend'],
				'strip_top' => ['roleid', 'readonly'],
				'rank' => 40
			],
			'usergroup' => [
				'label' => 'User group',
				'section' => 'Users',
				'mode' => self::MODE_API,
				'service' => 'usergroup',
				'id' => 'usrgrpid',
				'name' => 'name',
				'get' => [
					'output' => 'extend',
					'selectHostGroupRights' => 'extend',
					'selectTemplateGroupRights' => 'extend',
					'selectTagFilters' => 'extend',
					'selectUsers' => ['userid']
				],
				'strip_top' => ['usrgrpid'],
				'rank' => 41
			],
			'user' => [
				'label' => 'User',
				'section' => 'Users',
				'mode' => self::MODE_API,
				'service' => 'user',
				'id' => 'userid',
				'name' => 'username',
				'get' => ['output' => 'extend', 'selectUsrgrps' => ['usrgrpid'], 'selectMedias' => 'extend'],
				'strip_top' => ['userid', 'attempt_failed', 'attempt_ip', 'attempt_clock', 'ts_provisioned',
					'provisioned'
				],
				'strip_deep' => ['mediaid', 'userdirectory_mediaid', 'provisioned'],
				'refs' => ['roleid' => 'role', 'usrgrpid' => 'usergroup', 'mediatypeid' => 'mediatype'],
				'rank' => 42,
				'secrets' => 'Passwords are never readable. A recreated internal user gets a new random password, shown once after the restore.'
			],
			'usermacro' => [
				'label' => 'Global macro',
				'section' => 'Administration',
				'mode' => self::MODE_API,
				'service' => 'usermacro',
				'id' => 'globalmacroid',
				'name' => 'macro',
				'get' => ['output' => 'extend', 'globalmacro' => true],
				'create' => 'createglobal',
				'update' => 'updateglobal',
				'strip_top' => ['globalmacroid'],
				'rank' => 45,
				'secrets' => 'Secret text macro values are never readable and come back empty.'
			],
			'regexp' => [
				'label' => 'Regular expression',
				'section' => 'Administration',
				'mode' => self::MODE_API,
				'service' => 'regexp',
				'id' => 'regexpid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectExpressions' => 'extend'],
				'strip_top' => ['regexpid'],
				'strip_deep' => ['expressionid', 'regexpid'],
				'rank' => 45
			],
			'iconmap' => [
				'label' => 'Icon mapping',
				'section' => 'Administration',
				'mode' => self::MODE_API,
				'service' => 'iconmap',
				'id' => 'iconmapid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectMappings' => 'extend'],
				'strip_top' => ['iconmapid'],
				'strip_deep' => ['iconmappingid', 'iconmapid'],
				'refs' => ['iconid' => 'image', 'default_iconid' => 'image'],
				'rank' => 46
			],
			'script' => [
				'label' => 'Script',
				'section' => 'Alerts',
				'mode' => self::MODE_API,
				'service' => 'script',
				'id' => 'scriptid',
				'name' => 'name',
				'get' => ['output' => 'extend'],
				'strip_top' => ['scriptid'],
				'refs' => ['usrgrpid' => 'usergroup', 'groupid' => 'hostgroup'],
				'rank' => 50,
				'secrets' => 'SSH/Telnet script passwords and private key passphrases are never readable.'
			],
			'map' => [
				'label' => 'Map',
				'section' => 'Monitoring',
				'mode' => self::MODE_EXPORT,
				'service' => 'map',
				'id' => 'sysmapid',
				'name' => 'name',
				'list' => ['output' => ['sysmapid', 'name']],
				'export_key' => 'maps',
				'section_key' => 'maps',
				'rank' => 55
			],
			'service' => [
				'label' => 'Service',
				'section' => 'Services',
				'mode' => self::MODE_API,
				'service' => 'service',
				'id' => 'serviceid',
				'name' => 'name',
				'get' => [
					'output' => 'extend',
					'selectParents' => ['serviceid'],
					'selectChildren' => ['serviceid'],
					'selectTags' => 'extend',
					'selectProblemTags' => 'extend',
					'selectStatusRules' => 'extend'
				],
				'strip_top' => ['serviceid', 'status', 'readonly', 'created_at', 'uuid'],
				'refs' => ['serviceid' => 'service'],
				'rank' => 56
			],
			'sla' => [
				'label' => 'SLA',
				'section' => 'Services',
				'mode' => self::MODE_API,
				'service' => 'sla',
				'id' => 'slaid',
				'name' => 'name',
				'get' => [
					'output' => 'extend',
					'selectSchedule' => 'extend',
					'selectExcludedDowntimes' => 'extend',
					'selectServiceTags' => 'extend'
				],
				'strip_top' => ['slaid'],
				'rank' => 57
			],
			'drule' => [
				'label' => 'Discovery rule',
				'section' => 'Data collection',
				'mode' => self::MODE_API,
				'service' => 'drule',
				'id' => 'druleid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectDChecks' => 'extend'],
				'strip_top' => ['druleid', 'nextcheck', 'error'],
				'strip_deep' => ['dcheckid', 'druleid'],
				'refs' => ['proxyid' => 'proxy'],
				'rank' => 58
			],
			'maintenance' => [
				'label' => 'Maintenance',
				'section' => 'Data collection',
				'mode' => self::MODE_API,
				'service' => 'maintenance',
				'id' => 'maintenanceid',
				'name' => 'name',
				'get' => [
					'output' => 'extend',
					'selectHostGroups' => ['groupid'],
					'selectHosts' => ['hostid'],
					'selectTags' => 'extend',
					'selectTimeperiods' => 'extend'
				],
				'strip_top' => ['maintenanceid'],
				'strip_deep' => ['timeperiodid'],
				'refs' => ['groupid' => 'hostgroup', 'hostid' => 'host'],
				'rank' => 60
			],
			'correlation' => [
				'label' => 'Event correlation',
				'section' => 'Data collection',
				'mode' => self::MODE_API,
				'service' => 'correlation',
				'id' => 'correlationid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectFilter' => 'extend', 'selectOperations' => 'extend'],
				'strip_top' => ['correlationid'],
				'strip_deep' => ['eval_formula'],
				'rank' => 60
			],
			'action' => [
				'label' => 'Action',
				'section' => 'Alerts',
				'mode' => self::MODE_API,
				'service' => 'action',
				'id' => 'actionid',
				'name' => 'name',
				'get' => [
					'output' => 'extend',
					'selectFilter' => 'extend',
					'selectOperations' => 'extend',
					'selectRecoveryOperations' => 'extend',
					'selectUpdateOperations' => 'extend'
				],
				'strip_top' => ['actionid'],
				'strip_deep' => ['operationid', 'actionid', 'eval_formula'],
				'create_only' => ['eventsource'],
				'refs' => [
					'usrgrpid' => 'usergroup', 'userid' => 'user', 'mediatypeid' => 'mediatype',
					'scriptid' => 'script', 'groupid' => 'hostgroup', 'hostid' => 'host', 'templateid' => 'template'
				],
				'rank' => 70
			],
			'dashboard' => [
				'label' => 'Dashboard',
				'section' => 'Dashboards',
				'mode' => self::MODE_API,
				'service' => 'dashboard',
				'id' => 'dashboardid',
				'name' => 'name',
				'get' => [
					'output' => 'extend',
					'selectPages' => 'extend',
					'selectUsers' => 'extend',
					'selectUserGroups' => 'extend'
				],
				'strip_top' => ['dashboardid', 'uuid'],
				'strip_deep' => ['dashboard_pageid', 'widgetid'],
				'refs' => ['userid' => 'user', 'usrgrpid' => 'usergroup'],
				'rank' => 80
			],
			'report' => [
				'label' => 'Scheduled report',
				'section' => 'Reports',
				'mode' => self::MODE_API,
				'service' => 'report',
				'id' => 'reportid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectUsers' => 'extend', 'selectUserGroups' => 'extend'],
				'strip_top' => ['reportid', 'state', 'lastsent', 'info'],
				'refs' => [
					'dashboardid' => 'dashboard', 'userid' => 'user', 'access_userid' => 'user',
					'usrgrpid' => 'usergroup'
				],
				'rank' => 85
			],
			'connector' => [
				'label' => 'Connector',
				'section' => 'Administration',
				'mode' => self::MODE_API,
				'service' => 'connector',
				'id' => 'connectorid',
				'name' => 'name',
				'get' => ['output' => 'extend', 'selectTags' => 'extend'],
				'strip_top' => ['connectorid'],
				'rank' => 85,
				'secrets' => 'Bearer tokens, passwords and SSL key passwords of connectors are never readable.'
			],
			'settings' => [
				'label' => 'General settings',
				'section' => 'Administration',
				'mode' => self::MODE_SINGLETON,
				'service' => 'settings',
				'id' => null,
				'name' => null,
				'get' => ['output' => 'extend'],
				'strip_top' => [],
				'rank' => 90
			],
			'housekeeping' => [
				'label' => 'Housekeeping',
				'section' => 'Administration',
				'mode' => self::MODE_SINGLETON,
				'service' => 'housekeeping',
				'id' => null,
				'name' => null,
				'get' => ['output' => 'extend'],
				'strip_top' => ['compression_availability', 'db_extension'],
				'rank' => 90
			]
		];

		foreach ($types as $key => &$type) {
			$type += [
				'key' => $key,
				'strip_top' => [],
				'strip_deep' => [],
				'create_only' => [],
				'refs' => [],
				'secrets' => null,
				'tech' => null,
				'export_tech' => $type['tech'] ?? null,
				'create' => 'create',
				'update' => 'update'
			];
		}
		unset($type);

		uasort($types, static fn(array $a, array $b): int => [$a['rank'], $a['key']] <=> [$b['rank'], $b['key']]);

		return self::$types = $types;
	}

	public static function exists(string $type): bool {
		return array_key_exists($type, self::all());
	}

	public static function get(string $type): array {
		$types = self::all();

		if (!array_key_exists($type, $types)) {
			throw new \InvalidArgumentException(sprintf('Unknown object type "%s".', $type));
		}

		return $types[$type];
	}

	public static function labels(): array {
		return array_column(self::all(), 'label', 'key');
	}
}
