<?php declare(strict_types = 0);

namespace Modules\ConfigBackup;

use APP;
use CMenuItem;
use CWebUser;
use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
			return;
		}

		APP::Component()->get('menu.main')
			->findOrAdd(_('Administration'))
			->getSubmenu()
			->add(
				(new CMenuItem(_('Config backup')))->setAction('configbackup.list')->setAliases([
					'configbackup.snapshot', 'configbackup.object', 'configbackup.settings', 'configbackup.schedules',
					'configbackup.schedule.edit', 'configbackup.destinations', 'configbackup.destination.edit',
					'configbackup.remote'
				])
			);
	}
}
