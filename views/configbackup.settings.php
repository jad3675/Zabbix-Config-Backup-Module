<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

require_once __DIR__.'/inc/common.php';

$settings = $data['settings'];
$all = !$settings['types'];
$grey = static fn(string $t) => (new CDiv($t))->addClass(ZBX_STYLE_GREY);

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(cb_nav('configbackup.settings'))
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), cb_url('configbackup.list')), _('Settings')
	])));

if ($data['storage_problem'] !== null) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['storage_problem']]],
		_('Storage is not usable'), false
	));
}

$types = (new CDiv())->addClass('cb-types');
foreach ($data['sections'] as $section => $members) {
	$group = (new CDiv((new CDiv($section))->addClass('cb-types-title')))->addClass('cb-types-group');

	foreach ($members as $key => $label) {
		$group->addItem(new CDiv((new CCheckBox('types['.$key.']', $key))->setId('type_'.$key)->setLabel($label)
			->setChecked($all || in_array($key, $settings['types'], true))
		));
	}

	$types->addItem($group);
}

$tz_select = (new CSelect('timezone'))->setValue($data['timezone']);
foreach (DateTimeZone::listIdentifiers() as $zone) {
	$tz_select->addOption(new CSelectOption($zone, $zone));
}

$form = (new CForm())->setName('cb_settings');

$form->addItem((new CFormGrid())
	->addItem([(new CLabel(_('Storage path'), 'storage'))->setAsteriskMark(), new CFormField([
		(new CTextBox('storage', $data['storage'], false, 1024))->setWidth(ZBX_TEXTAREA_BIG_WIDTH),
		$grey(sprintf(_('Local directory on the frontend server, writable by "%1$s". Snapshots, runner state and the key that seals stored passwords live here.'), $data['process_user']))
	])])
	->addItem((new CTag('h4', true, _('Runner')))->addClass('cb-grid-heading'))
	->addItem([new CLabel(_('Status')), new CFormField(cb_runner_line($data['runner']))])
	->addItem([new CLabel(_('Zabbix URL'), 'api_url'), new CFormField([
		(new CTextBox('api_url', $data['suggested_url'], false, 1024))->setWidth(ZBX_TEXTAREA_BIG_WIDTH),
		$grey(_('How the runner reaches this frontend, normally http://127.0.0.1/zabbix or the URL above it.'))
	])])
	->addItem([new CLabel(_('TLS'), 'api_verify_tls'), new CFormField([
		(new CCheckBox('api_verify_tls'))->setLabel(_('Verify TLS certificate'))->setChecked($settings['api']['verify_tls']),
		new CDiv([
			(new CTextBox('api_ca_file', $settings['api']['ca_file'], false, 1024))
				->setWidth(ZBX_TEXTAREA_BIG_WIDTH)
				->setAttribute('placeholder', '/etc/ssl/certs/internal-ca.pem')
		]),
		$grey(_('For https with a self-signed certificate or an internal CA: give the CA certificate file (PEM) above, which keeps checking, or untick verification. Unticked, the runner trusts whatever answers at that address, so prefer http://127.0.0.1/zabbix on the same server, or the CA file.'))
	])])
	->addItem([new CLabel(_('API token'), 'api_token'), new CFormField([
		(new CPassBox('api_token', '', 128))->setWidth(ZBX_TEXTAREA_BIG_WIDTH)
			->setAttribute('placeholder', $data['token_set'] ? _('stored; type to replace') : _('token of a Super admin user'))
			->setAttribute('autocomplete', 'new-password'),
		$data['token_set'] ? new CDiv((new CCheckBox('api_token_clear'))->setLabel(_('Remove the stored token'))) : null,
		$grey(_('Users > API tokens. Super admin, because a snapshot reads users, roles and every action. The runner only ever reads; restores happen here, as you.'))
	])])
	->addItem((new CTag('h4', true, _('Schedules')))->addClass('cb-grid-heading'))
	->addItem([new CLabel(_('Time zone'), 'timezone'), new CFormField([
		$tz_select,
		$data['timezone_chosen'] ? null : (new CDiv(
			_('Not saved yet: schedules run in UTC until you press Update. The suggestion is Zabbix\'s default time zone.')
		))->addClass(ZBX_STYLE_ORANGE),
		$grey(_('Schedule times mean this zone, for the runner, this page and the command line alike, whatever php.ini or your user profile say. Changing it starts each schedule fresh from its next slot.'))
	])])
	->addItem((new CTag('h4', true, _('Back up now and command line')))->addClass('cb-grid-heading'))
	->addItem([new CLabel(_('Keep')), new CFormField([
		(new CNumericBox('keep_count', $settings['keep_count'], 6))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
		' ', _('newest'), ' ',
		(new CNumericBox('keep_days', $settings['keep_days'], 5))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
		' ', _('days at most (0 = no age limit)'),
		$grey(_('For manual snapshots. Schedules have their own retention. Pinned snapshots and the newest one are never removed.'))
	])])
	->addItem([new CLabel(_('Object types')), new CFormField([
		new CDiv((new CCheckBox('all_types'))->setLabel(_('All (types added in later versions are included automatically)'))
			->setChecked($all)->setAttribute('data-cb-all', 'types')
		),
		$types
	])])
	->addItem([new CLabel(_('Warn after'), 'expect_hours'), new CFormField([
		(new CNumericBox('expect_hours', $settings['expect_hours'], 4))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
		' ', _('hours without any schedule enabled (0 = never)')
	])])
	->addItem(new CFormActions(
		(new CSimpleButton(_('Update')))
			->setAttribute('data-cb-action', 'configbackup.settings.update')
			->setAttribute('data-cb-token', $data['csrf'])
	))
);

$html_page->addItem($form);

/*
 * Notify through Zabbix.
 */
$mon = $settings['monitoring'];
$runner = $data['runner'];

$mon_status = [];
if ($mon['enabled']) {
	$mon_status[] = $data['monitor_host_id'] !== null
		? [cb_state(_('On'), 'same'), ' ', new CLink(sprintf(_('Problems on "%1$s"'), $mon['host']),
			(new CUrl('zabbix.php'))->setArgument('action', 'problem.view')->setArgument('hostids[]', $data['monitor_host_id'])
				->setArgument('filter_set', 1)
		)]
		: [cb_state(_('Host missing'), 'missing'), ' ', sprintf(_('"%1$s" does not exist; press Create or update to recreate it.'), $mon['host'])];

	if (isset($runner['monitor_time'])) {
		$mon_status[] = new CDiv([
			($runner['monitor_ok'] ?? false) ? cb_state(_('Reporting'), 'same') : cb_state(_('Not reporting'), 'missing'),
			' ', sprintf(_('last %1$s: %2$s'), cb_ago($runner['monitor_time']), $runner['monitor_message'] ?? '')
		]);
	}
	else {
		$mon_status[] = new CDiv((new CSpan(_('The runner has not reported yet.')))->addClass(ZBX_STYLE_GREY));
	}
}
else {
	$mon_status[] = cb_state(_('Off'), 'unknown');
}

$mon_form = (new CForm())->setName('cb_monitor');
$mon_form->addItem((new CFormGrid())
	->addItem((new CTag('h4', true, _('Notify through Zabbix')))->addClass('cb-grid-heading'))
	->addItem([new CLabel(_('Status')), new CFormField($mon_status)])
	->addItem([new CLabel(_('Host'), 'monitor_host'), new CFormField([
		(new CTextBox('monitor_host', $mon['host'], false, 128))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH),
		' ', _('in host group'), ' ',
		(new CTextBox('monitor_group', $mon['group'], false, 255))->setWidth(ZBX_TEXTAREA_SMALL_WIDTH),
		$grey(sprintf(_('A host without interfaces, linked to the template "%1$s". The runner pushes its state there every pass (history.push, with the token above), and problems go through your normal actions and media.'), Modules\ConfigBackup\Lib\Monitor::TEMPLATE))
	])])
	->addItem([new CLabel(_('Problems raised')), new CFormField((new CDiv(implode("\n", [
		_('Average: no word from the runner for 20m (timer stopped, server down, or the API unreachable)'),
		_('Average: the runner cannot use the Zabbix API'),
		_('Average: a schedule failed (resolves on the next successful run)'),
		_('High: a schedule is overdue (no successful run by its next slot plus an hour)'),
		_('Warning: a schedule could not read part of the configuration'),
		_('Warning: sending to a destination failed')
	])))->addClass('cb-note cb-pre-line'))])
	->addItem(new CFormActions(
		(new CSimpleButton($mon['enabled'] ? _('Update host and template') : _('Create host and turn on')))
			->setAttribute('data-cb-action', 'configbackup.monitor.setup')
			->setAttribute('data-cb-token', $data['csrf_monitor']),
		$mon['enabled']
			? [(new CSimpleButton(_('Turn off')))
				->addClass(ZBX_STYLE_BTN_ALT)
				->setAttribute('data-cb-action', 'configbackup.monitor.setup')
				->setAttribute('data-cb-token', $data['csrf_monitor'])
				->setAttribute('data-cb-confirm', _('Stop reporting? The host stays; disable or delete it, or its "no word from the runner" problem will fire.'))
				->setAttribute('data-cb-op', 'disable')]
			: []
	))
);
$mon_form->addVar('op', 'enable');

$html_page->addItem($mon_form);

/*
 * Installing the runner.
 */
$user = $data['process_user'];
$script = $data['module_dir'].'/bin/zbx-config-backup.php';
$storage_arg = $data['storage'] !== Modules\ConfigBackup\Lib\Settings::DEFAULT_STORAGE ? ' --storage='.$data['storage'] : '';

$unit = implode("\n", [
	'# /etc/systemd/system/zabbix-configbackup.service',
	'[Unit]',
	'Description=Zabbix config backup runner (schedules and queued requests)',
	'After=network-online.target',
	'',
	'[Service]',
	'Type=oneshot',
	'User='.$user,
	'Group='.$user,
	'ExecStart=/usr/bin/php '.$script.' run-due --quiet'.$storage_arg,
	'Nice=10',
	'',
	'# /etc/systemd/system/zabbix-configbackup.timer',
	'[Unit]',
	'Description=Zabbix config backup runner, every 5 minutes',
	'',
	'[Timer]',
	'OnBootSec=2min',
	'OnUnitActiveSec=5min',
	'AccuracySec=30s',
	'',
	'[Install]',
	'WantedBy=timers.target'
]);

$html_page->addItem([
	(new CDiv(_('Installing the runner')))->addClass('cb-section-title'),
	(new CDiv(_('Schedules, sending to destinations, tests and pulls are done by a small runner started every 5 minutes. It exits in milliseconds when nothing is due. As root, once:')))->addClass('cb-note'),
	(new CPre(implode("\n", [
		'sh '.$data['module_dir'].'/contrib/install-runner.sh'.($storage_arg !== '' ? ' --storage='.$data['storage'] : ''),
		'',
		'# it fixes ownership and permissions of the storage path, installs the timer and runs a check.',
		'# status at any time:',
		'sudo -u '.$user.' php '.$script.' status'.$storage_arg,
		'',
		'# remove the runner (keeps snapshots; --purge deletes them too):',
		'sh '.$data['module_dir'].'/contrib/uninstall-runner.sh'
	])))->addClass('cb-code'),
	(new CTag('details', true, [
		new CTag('summary', true, _('Unit files the installer writes')),
		(new CPre($unit))->addClass('cb-code')
	]))->addClass('cb-details')
]);

$html_page->show();

$this->includeJsFile('configbackup.js.php');
