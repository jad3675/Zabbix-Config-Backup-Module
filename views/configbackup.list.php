<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

require_once __DIR__.'/inc/common.php';

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(cb_nav('configbackup.list'));

if ($data['storage_problem'] !== null) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['storage_problem']]],
		_('Storage is not usable'), false
	));
}
elseif ($data['warnings']) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING,
		array_map(static fn($w) => ['message' => $w], $data['warnings']), _('Backups need attention'), false
	));
}

/*
 * Back up now.
 */
$backup_form = (new CForm())->setName('cb_backup');
$send = [];

foreach ($data['destinations'] as $id => $name) {
	$send[] = (new CCheckBox('send['.$id.']', $id))
		->setId('send_'.$id)
		->setLabel($name)
		->setChecked(in_array($id, $data['manual_destinations'], true));
}

$backup_form->addItem(
	(new CDiv([
		(new CSpan(_('Label')))->addClass('cb-bar-label'),
		(new CTextBox('label', '', false, 255))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', _('optional, e.g. before upgrade to 7.4.16')),
		(new CSimpleButton(_('Back up now')))
			->setAttribute('data-cb-action', 'configbackup.backup')
			->setAttribute('data-cb-token', $data['csrf']['backup'])
			->setEnabled($data['storage_problem'] === null),
		$send ? [(new CSpan(_('then send to')))->addClass('cb-bar-label'), $send] : null
	]))->addClass('cb-bar')
);

$html_page->addItem($backup_form);

/*
 * Status: runner, next schedule, queue.
 */
$status = [
	cb_runner_line($data['runner']),
	(new CDiv($data['next'] !== null
		? sprintf(_('Next scheduled: "%1$s" at %2$s %3$s.'), $data['next'][1],
			Modules\ConfigBackup\Lib\Schedule::format($data['next'][0], $data['timezone']), $data['timezone'])
		: _('No schedule enabled.')
	))->addClass('cb-bar-label')
];

if ($data['queue']) {
	$status[] = (new CDiv(_('Waiting for the runner').': '.implode('; ', $data['queue'])))->addClass('cb-bar-label');
}

foreach ($data['recent'] as $r) {
	if ($r['finished'] > time() - 86400) {
		$status[] = (new CDiv([
			$r['ok'] ? cb_state(_('Done'), 'same') : cb_state(_('Failed'), 'missing'),
			' ', $r['text'], ': ', $r['message'],
			(new CSpan(' ('.cb_ago($r['finished']).')'))->addClass(ZBX_STYLE_GREY)
		]))->addClass('cb-bar-label');
	}
}

$html_page->addItem((new CDiv($status))->addClass('cb-bar cb-status'));

/*
 * Snapshots: local and remote-only, one history.
 */
$form = (new CForm())->setName('cb_snapshots');

$schedule_names = array_column($data['settings']['schedules'], 'name', 'id');

$html_page->addItem((new CDiv([
	(new CSpan($data['show_remote']
		? _('Local snapshots and copies that only exist on destinations, newest first.')
		: _('Local snapshots only.')
	))->addClass('cb-bar-label'),
	(new CSpan())->addClass('cb-spacer'),
	$data['show_remote']
		? new CLink(sprintf(_('Hide remote-only (%1$d)'), $data['remote_count']), cb_url('configbackup.list', ['remote' => 0]))
		: new CLink(sprintf(_('Show remote-only (%1$d)'), $data['remote_count']), cb_url('configbackup.list'))
]))->addClass('cb-bar'));

$table = (new CTableInfo())->setHeader([
	(new CColHeader((new CCheckBox('all_snapshots'))->setAttribute('data-cb-all', 'snapshotids')))
		->addClass(ZBX_STYLE_CELL_WIDTH),
	_('Snapshot'), _('Taken'), _('Source'), _('Objects'), _('Size'), _('Copies'), _('Status'), ''
]);

foreach ($data['snapshots'] as $id => $meta) {
	$title = $meta['label'] !== '' ? $meta['label'] : zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['created']);

	switch ($meta['source']) {
		case 'manual':
			$source = ($meta['user'] ?? '') !== '' ? sprintf(_('manual (%1$s)'), $meta['user']) : _('manual');
			break;

		case 'schedule':
		case '':
			$source = $meta['schedule'] !== null && $meta['schedule'] !== ''
				? sprintf(_('schedule (%1$s)'), $schedule_names[$meta['schedule']] ?? _('deleted'))
				: ($meta['source'] === '' ? '' : _('schedule'));
			break;

		default:
			$source = $meta['source'];
	}

	if (!empty($meta['pulled_from'])) {
		$source = [$source, ' ', cb_state(_('pulled'), 'present',
			sprintf(_('Copied back from %1$s %2$s'), $data['destinations'][$meta['pulled_from']['destination']] ?? '?',
				zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['pulled_from']['time'])
			)
		)];
	}

	$copies = [];
	$pull_from = null;

	foreach ($meta['copies'] as $destid => $copy) {
		$hint = !empty($copy['git'])
			? _('Committed to Git history')
			: ($copy['encrypted'] ? _('Encrypted').', ' : '').Modules\ConfigBackup\Lib\Shipper::bytes($copy['size'])
				.($copy['listed'] ? ', '.sprintf(_('listed %1$s'), cb_ago($copy['listed'])) : '');
		$copies[] = cb_state(($data['destinations'][$destid] ?? '?').(!empty($copy['encrypted']) ? ' 🔒' : ''), 'present', $hint);
		$copies[] = ' ';

		if (empty($copy['git']) && $copy['pullable'] && ($pull_from === null || !$copy['encrypted'])) {
			$pull_from = [$destid, $copy['name']];
		}
	}

	if ($meta['local']) {
		$status = $meta['status'] === 'ok'
			? cb_state(_('OK'), 'same')
			: [cb_state(_('Partial'), 'changed'), ' ', makeWarningIcon(implode("\n", array_map(
				static fn($type, $messages) => $type.': '.implode('; ', array_slice($messages, 0, 5)),
				array_keys($meta['errors']), $meta['errors']
			)))];

		$table->addRow([
			new CCheckBox('snapshotids['.$id.']', $id),
			[
				new CLink($title, cb_url('configbackup.snapshot', ['snapshotid' => $id])),
				$meta['pinned'] ? [' ', cb_state(_('pinned'), 'present')] : null
			],
			[
				zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['created']), ' ',
				(new CSpan('('.zbx_date2age($meta['created']).')'))->addClass(ZBX_STYLE_GREY)
			],
			$source,
			array_sum($meta['counts']),
			convertUnits(['value' => $meta['size'], 'units' => 'B']),
			$copies ?: (new CSpan(_('local only')))->addClass(ZBX_STYLE_GREY),
			$status,
			(new CCol($meta['note']))->addClass(ZBX_STYLE_WORDBREAK)
		]);

		continue;
	}

	// Remote only.
	if ($pull_from !== null) {
		$action = cb_post_button(_('Pull and open'), 'configbackup.pull', $data['csrf']['pull'], [
			'destination' => $pull_from[0], 'name' => $pull_from[1], 'back' => 'list'
		], null, false);
	}
	else {
		$first = array_key_first($meta['copies']);
		$action = (new CSpan(_('Encrypted: pull with your private key')))
			->addClass(ZBX_STYLE_GREY)
			->setTitle(sprintf('sudo -u %s php .../bin/zbx-config-backup.php pull "%s" %s --key=/path/to/private.pem',
				$data['process_user'], $data['destinations'][$first] ?? '?', $meta['copies'][$first]['name'] ?? ''
			));
	}

	$table->addRow((new CRow([
		'',
		[(new CSpan($title))->addClass(ZBX_STYLE_GREY), ' ', cb_state(_('remote only'), 'present',
			_('Not on this server any more. Pull it to browse and restore from it.'))
		],
		[
			zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['created']), ' ',
			(new CSpan('('.zbx_date2age($meta['created']).')'))->addClass(ZBX_STYLE_GREY)
		],
		$source,
		$meta['objects'] ?? (new CSpan('?'))->addClass(ZBX_STYLE_GREY)->setTitle(_('Not sent from this server, so its contents are unknown until pulled.')),
		convertUnits(['value' => $meta['size'], 'units' => 'B']),
		$copies,
		$meta['status'] === null || $meta['status'] === 'ok' ? cb_state(_('Remote'), 'unknown') : cb_state(_('Partial'), 'changed'),
		$action
	]))->addClass('cb-remote-row'));
}

$form->addItem([
	$table,
	(new CDiv(
		(new CSimpleButton(_('Delete')))
			->setAttribute('data-cb-action', 'configbackup.snapshot.delete')
			->setAttribute('data-cb-token', $data['csrf']['delete'])
			->setAttribute('data-cb-needs-selection', 'snapshotids')
			->setAttribute('data-cb-confirm', _('Delete the selected local snapshots? Copies on destinations are not touched.'))
			->addClass(ZBX_STYLE_BTN_ALT)
	))->addClass('cb-bar')
]);

$html_page->addItem($form)->show();

$this->includeJsFile('configbackup.js.php');
