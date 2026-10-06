<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

require_once __DIR__.'/inc/common.php';

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(cb_nav('configbackup.destinations'))
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), cb_url('configbackup.list')), _('Destinations')
	])));

if ($data['storage_problem'] !== null) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['storage_problem']]], null, false));
}

$html_page->addItem((new CDiv([
	new CRedirectButton(_('New S3 bucket'), cb_url('configbackup.destination.edit', ['kind' => 's3'])),
	new CRedirectButton(_('New SFTP server'), cb_url('configbackup.destination.edit', ['kind' => 'sftp'])),
	new CRedirectButton(_('New Git repository'), cb_url('configbackup.destination.edit', ['kind' => 'git'])),
	(new CSpan())->addClass('cb-spacer'),
	cb_runner_line($data['runner'])
]))->addClass('cb-bar'));

$table = (new CTableInfo())->setHeader([
	_('Name'), _('Type'), _('Where'), _('Encrypted'), _('Keep'), _('Last send'), _('Last test'), ''
]);

$queued = [];
foreach ($data['pending'] as $r) {
	if (isset($r['destination'])) {
		$queued[$r['destination']][] = $r['kind'];
	}
}

foreach ($data['settings']['destinations'] as $d) {
	$st = $data['state'][$d['id']] ?? [];

	switch ($d['kind']) {
		case 's3':
			$where = 's3://'.$d['bucket'].'/'.$d['prefix'].($d['endpoint'] !== '' ? ' @ '.parse_url($d['endpoint'], PHP_URL_HOST) : '');
			break;

		case 'sftp':
			$where = $d['username'].'@'.$d['host'].':'.($d['path'] !== '' ? $d['path'] : '~');
			break;

		default:
			$where = $d['url'].' ('.$d['branch'].')';
	}

	$encrypted = $d['kind'] === 'git'
		? (new CSpan(_('n/a')))->addClass(ZBX_STYLE_GREY)->setTitle(_('Git keeps readable diffs; keep the repository private.'))
		: ($d['public_key'] !== '' ? cb_state(_('Yes'), 'same') : cb_state(_('No'), 'changed', _('Snapshots hold e-mail addresses, SNMP communities and webhook URLs.')));

	$last = isset($st['last_time'])
		? [($st['last_ok'] ?? false) ? cb_state(_('OK'), 'same') : cb_state(_('Failed'), 'missing'), ' ',
			(new CSpan(cb_ago($st['last_time'])))->setTitle($st['last_message'] ?? '')]
		: (new CSpan(_('never')))->addClass(ZBX_STYLE_GREY);

	$test = isset($st['test_time'])
		? [($st['test_ok'] ?? false) ? cb_state(_('OK'), 'same') : cb_state(_('Failed'), 'missing'), ' ',
			(new CSpan(cb_ago($st['test_time'])))->setTitle($st['test_message'] ?? '')]
		: (new CSpan(_('never')))->addClass(ZBX_STYLE_GREY);

	if (!empty($queued[$d['id']])) {
		$test = [$test, ' ', cb_state(_('queued'), 'present', implode(', ', $queued[$d['id']]))];
	}

	$actions = [
		cb_post_button(_('Test'), 'configbackup.queue', $data['csrf']['queue'],
			['kind' => 'test', 'destination' => $d['id'], 'back' => 'destinations']
		)
	];

	if ($d['kind'] !== 'git') {
		$actions[] = ' ';
		$actions[] = new CLink(_('Snapshots there'), cb_url('configbackup.remote', ['destination' => $d['id']]));
	}

	$actions[] = ' ';
	$actions[] = cb_post_button(_('Delete'), 'configbackup.destination.update', $data['csrf']['update'],
		['op' => 'delete', 'id' => $d['id']], _('Delete this destination? Files already stored there are left alone.')
	);

	$table->addRow([
		[new CLink($d['name'], cb_url('configbackup.destination.edit', ['id' => $d['id']])),
			$d['enabled'] ? null : [' ', cb_state(_('disabled'), 'unknown')],
			$d['manual'] ? [' ', cb_state(_('Back up now'), 'present', _('Also receives manual snapshots by default'))] : null
		],
		strtoupper($d['kind']),
		(new CCol($where))->addClass(ZBX_STYLE_WORDBREAK),
		$encrypted,
		$d['kind'] === 'git' ? (new CSpan(_('history')))->addClass(ZBX_STYLE_GREY) : $d['keep_count'].($d['keep_days'] ? ' / '.$d['keep_days'].'d' : ''),
		$last,
		$test,
		$actions
	]);
}

$html_page->addItem($table)->show();

$this->includeJsFile('configbackup.js.php');
