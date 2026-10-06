<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

use Modules\ConfigBackup\Lib\Shipper;

require_once __DIR__.'/inc/common.php';

$d = $data['d'];

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), cb_url('configbackup.list')),
		new CLink(_('Destinations'), cb_url('configbackup.destinations')),
		$d['name']
	])));

$html_page->addItem((new CDiv([
	cb_post_button(_('Refresh list'), 'configbackup.queue', $data['csrf'],
		['kind' => 'list', 'destination' => $d['id'], 'back' => 'remote'], null, false
	),
	(new CSpan($data['listing_time'] !== null
		? sprintf(_('As listed %1$s. The runner refreshes this every 6 hours and after every send.'), cb_ago($data['listing_time']))
		: _('Not listed yet. Press Refresh list and give the runner up to 5 minutes.')
	))->addClass('cb-bar-label'),
	$data['pending'] ? cb_state(sprintf(_('%1$d request(s) queued'), count($data['pending'])), 'present') : null
]))->addClass('cb-bar'));

$table = (new CTableInfo())->setHeader([_('Snapshot'), _('From'), _('Taken'), _('Size'), _('Encrypted'), _('Here'), '']);
$schedule_names = array_column($data['schedules'], 'name', 'id');

foreach ($data['listing'] as $name => $info) {
	preg_match(Shipper::NAME, $name, $m);
	$id = $m[1];
	$encrypted = !empty($m[3]);
	$tag = ($m[2] ?? '') !== '' ? $m[2] : 'manual';
	$taken = strtotime(substr($id, 0, 8).'T'.substr($id, 9, 6).'Z');

	if (isset($data['local'][$id])) {
		$action = new CLink(_('Open local copy'), cb_url('configbackup.snapshot', ['snapshotid' => $id]));
	}
	elseif ($encrypted && !$data['can_decrypt']) {
		$action = (new CSpan(_('Pull with the CLI and your private key')))->addClass(ZBX_STYLE_GREY)
			->setTitle('zbx-config-backup.php pull "'.$d['name'].'" '.$name.' --key=/path/to/private.pem');
	}
	else {
		$action = cb_post_button(_('Pull and open'), 'configbackup.pull', $data['csrf_pull'],
			['destination' => $d['id'], 'name' => $name, 'back' => 'remote'], null, false
		);
	}

	$table->addRow([
		$name,
		$tag === 'manual' ? _('manual') : ($schedule_names[$tag] ?? $tag),
		zbx_date2str(DATE_TIME_FORMAT_SECONDS, $taken),
		Shipper::bytes((int) $info['size']),
		$encrypted ? cb_state(_('Yes'), 'same') : cb_state(_('No'), 'changed'),
		isset($data['local'][$id]) ? cb_state(_('Yes'), 'same') : (new CSpan(_('No')))->addClass(ZBX_STYLE_GREY),
		$action
	]);
}

$html_page->addItem($table)->addItem((new CDiv(
	_('To rebuild a lost Zabbix server: install Zabbix and this module, add this destination, pull the snapshot, then restore from it. For an encrypted copy without any Zabbix at all: download the file and run "zbx-config-backup.php decrypt FILE --key=private.pem", which gives a plain tar.')
))->addClass('cb-note cb-bar-label'))->show();

$this->includeJsFile('configbackup.js.php');
