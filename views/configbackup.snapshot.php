<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

use Modules\ConfigBackup\Lib\Types;

require_once __DIR__.'/inc/common.php';

$url = static function (string $action, array $args = []): CUrl {
	$url = (new CUrl('zabbix.php'))->setArgument('action', $action);

	foreach ($args as $key => $value) {
		if ($value !== null && $value !== '') {
			$url->setArgument($key, $value);
		}
	}

	return $url;
};

$state_labels = [
	'missing' => _('Missing'),
	'recreated' => _('Recreated'),
	'changed' => _('Changed'),
	'same' => _('Unchanged'),
	'present' => _('Exists')
];

$state_hints = [
	'missing' => _('Gone from the live system.'),
	'recreated' => _('Something with this name exists, but under a different ID.'),
	'changed' => _('Exists, configuration differs from the snapshot.'),
	'same' => _('Exists and matches the snapshot.'),
	'present' => _('Exists. Open it to compare with the snapshot.')
];

$meta = $data['meta'];
$snapshotid = $meta['id'];
$taken = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['created']);
$labels = Types::labels();

$html_page = (new CHtmlPage())
	->setTitle($meta['label'] !== '' ? $meta['label'] : sprintf(_('Snapshot %1$s'), $taken))
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), $url('configbackup.list')),
		$meta['label'] !== '' ? $meta['label'] : $taken
	])));

foreach ($data['errors'] as $error) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $error]],
		_('Cannot compare with the live system'), false
	));
}

/*
 * Snapshot details: label, note, pin.
 */
$source = $meta['source'] === 'manual' && $meta['user'] !== ''
	? sprintf(_('manual (%1$s)'), $meta['user'])
	: $meta['source'];

$details = (new CForm())->setName('cb_details')->addVar('snapshotid', $snapshotid);
$details->addItem(
	(new CFormGrid())
		->addItem([
			new CLabel(_('Taken')),
			new CFormField([
				$taken, ' ', (new CSpan('('.zbx_date2age($meta['created']).' '._('ago').')'))->addClass(ZBX_STYLE_GREY),
				', ', $source,
				$meta['zabbix_version'] ? ', Zabbix '.$meta['zabbix_version'] : '',
				', ', sprintf(_('%1$d objects'), array_sum($meta['counts'])),
				', ', convertUnits(['value' => $data['size'], 'units' => 'B']),
				', ', sprintf(_('%1$ss'), $meta['duration'])
			])
		])
		->addItem($meta['errors'] ? [
			new CLabel(_('Errors')),
			new CFormField(
				(new CDiv(implode("\n", array_merge(...array_map(
					static fn($type, $messages) => array_map(static fn($m) => ($labels[$type] ?? $type).': '.$m, $messages),
					array_keys($meta['errors']), array_values($meta['errors'])
				)))))->addClass('cb-code')
			)
		] : null)
		->addItem([
			new CLabel(_('Label'), 'label'),
			new CFormField((new CTextBox('label', $meta['label'], false, 255))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
		])
		->addItem([
			new CLabel(_('Note'), 'note'),
			new CFormField((new CTextArea('note', $meta['note']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)->setRows(2))
		])
		->addItem([
			new CLabel(_('Pinned'), 'pinned'),
			new CFormField([
				(new CCheckBox('pinned'))->setChecked($meta['pinned']),
				(new CDiv(_('Pinned snapshots are never removed by retention.')))->addClass(ZBX_STYLE_GREY)
			])
		])
		->addItem(new CFormActions(
			(new CSimpleButton(_('Save')))
				->setAttribute('data-cb-action', 'configbackup.snapshot.update')
				->setAttribute('data-cb-token', $data['csrf']['update']),
			[
				(new CSimpleButton(_('Delete snapshot')))
					->addClass(ZBX_STYLE_BTN_ALT)
					->setAttribute('data-cb-action', 'configbackup.snapshot.delete')
					->setAttribute('data-cb-token', $data['csrf']['delete'])
					->setAttribute('data-cb-confirm', _('Delete this snapshot? This cannot be undone.'))
			]
		))
);
$details->addVar('snapshotids[]', $snapshotid);

$details_block = (new CTag('details', true, [new CTag('summary', true, _('Snapshot details')), $details]))
	->addClass('cb-details');

if ($meta['errors']) {
	$details_block->setAttribute('open', 'open');
}

if (!empty($meta['pulled_from'])) {
	$html_page->addItem((new CDiv([
		cb_state(_('pulled'), 'present'), ' ',
		sprintf(_('Copied back from %1$s on %2$s.'), $data['destinations_all'][$meta['pulled_from']['destination']] ?? _('a deleted destination'),
			zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['pulled_from']['time'])
		),
		' ',
		$meta['pinned']
			? [_('Pinned so retention leaves it alone while you work with it.'), ' ',
				cb_post_button(_('Unpin, I am done'), 'configbackup.snapshot.update', $data['csrf']['update'], [
					'snapshotid' => $snapshotid, 'label' => $meta['label'], 'note' => $meta['note'], 'pinned' => 0
				], _('Unpin it? Retention may then remove it on the next run, since it is older than the snapshots kept.'))
			]
			: _('Not pinned: retention may remove it.')
	]))->addClass('cb-bar'));
}

$html_page->addItem($details_block);

/*
 * Copies elsewhere.
 */
$copies = [];
foreach ($data['copies'] as $destid => $info) {
	$copies[] = (new CSpan(($data['destinations_all'][$destid] ?? _('deleted destination')).(str_ends_with($info['name'], '.cbk') ? ' 🔒' : '').' ('.zbx_date2age($info['time']).' '._('ago').')'))
		->addClass('cb-state cb-state-present');
	$copies[] = ' ';
}

if ($data['destinations']) {
	$send_select = new CSelect('destination');
	foreach ($data['destinations'] as $id => $name) {
		$send_select->addOption(new CSelectOption($id, $name));
	}

	$html_page->addItem((new CForm())
		->setName('cb_send')
		->addVar('kind', 'upload')
		->addVar('snapshotid', $snapshotid)
		->addVar('back', 'snapshot')
		->addItem((new CDiv([
			(new CSpan(_('Copies')))->addClass('cb-bar-label'),
			$copies ?: (new CSpan(_('local only')))->addClass(ZBX_STYLE_GREY),
			(new CSpan())->addClass('cb-spacer'),
			(new CSpan(_('Send to')))->addClass('cb-bar-label'),
			$send_select,
			(new CSimpleButton(_('Send')))
				->addClass(ZBX_STYLE_BTN_ALT)
				->setAttribute('data-cb-action', 'configbackup.queue')
				->setAttribute('data-cb-token', $data['csrf']['queue'])
		]))->addClass('cb-bar'))
	);
}

/*
 * Filter.
 */
$type_select = (new CSelect('filter_type'))
	->setValue($data['filter']['type'])
	->addOption(new CSelectOption('', _('All types')));

foreach (Types::all() as $key => $type) {
	if (!empty($meta['counts'][$key])) {
		$type_select->addOption(new CSelectOption($key, sprintf('%s (%d)', $type['label'], $meta['counts'][$key])));
	}
}

$state_select = (new CSelect('filter_state'))
	->setValue($data['filter']['state'])
	->addOption(new CSelectOption('', _('Any state')));

foreach ($state_labels as $state => $label) {
	$state_select->addOption(new CSelectOption($state, $label));
}

$filter = (new CForm('get'))
	->setName('cb_filter')
	->addVar('action', 'configbackup.snapshot')
	->addVar('snapshotid', $snapshotid)
	->addItem(
		(new CDiv([
			(new CSpan(_('Type')))->addClass('cb-bar-label'),
			$type_select,
			(new CSpan(_('Name')))->addClass('cb-bar-label'),
			(new CTextBox('filter_name', $data['filter']['name']))->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH),
			(new CSpan(_('State')))->addClass('cb-bar-label'),
			$state_select,
			new CSubmitButton(_('Apply')),
			(new CRedirectButton(_('Reset'), $url('configbackup.snapshot', ['snapshotid' => $snapshotid])))
				->addClass(ZBX_STYLE_BTN_ALT),
			(new CSpan())->addClass('cb-spacer'),
			new CLink(_('Show everything that is gone'),
				$url('configbackup.snapshot', ['snapshotid' => $snapshotid, 'filter_state' => 'missing'])
			)
		]))->addClass('cb-bar')
	);

$html_page->addItem($filter);

if ($data['compare'] && $data['summary']) {
	$summary = [];

	foreach ($state_labels as $state => $label) {
		if (!empty($data['summary'][$state])) {
			$summary[] = (new CLink($label.': '.$data['summary'][$state], $url('configbackup.snapshot', [
				'snapshotid' => $snapshotid,
				'filter_type' => $data['filter']['type'],
				'filter_name' => $data['filter']['name'],
				'filter_state' => $state
			])))->addClass('cb-state cb-state-'.$state);
		}
	}

	$html_page->addItem((new CDiv($summary))->addClass('cb-bar cb-summary'));
}

/*
 * Objects.
 */
$form = (new CForm())
	->setName('cb_objects')
	->addVar('snapshotid', $snapshotid)
	->addVar('back', 'snapshot')
	->addVar('filter_type', $data['filter']['type'])
	->addVar('filter_name', $data['filter']['name'])
	->addVar('filter_state', $data['filter']['state']);

$table = (new CTableInfo())->setHeader([
	(new CColHeader((new CCheckBox('all_items'))->setAttribute('data-cb-all', 'items')))->addClass(ZBX_STYLE_CELL_WIDTH),
	_('Type'),
	_('Name'),
	_('ID'),
	_('Now'),
	''
]);

foreach ($data['rows'] as $row) {
	$object_url = $url('configbackup.object', [
		'snapshotid' => $snapshotid, 'type' => $row['type'], 'id' => $row['id']
	]);

	if ($row['state'] !== null) {
		$state = (new CSpan($state_labels[$row['state']]))
			->addClass('cb-state cb-state-'.$row['state'])
			->setTitle($state_hints[$row['state']]);

		if ($row['state'] === 'recreated') {
			$state = [$state, ' ', (new CSpan(sprintf(_('ID %1$s'), $row['live_id'])))->addClass(ZBX_STYLE_GREY)];
		}
	}
	else {
		$state = (new CSpan('?'))->addClass('cb-state cb-state-unknown')->setTitle(_('Not compared'));
	}

	$restored = $data['remap'][$row['type']][$row['id']] ?? null;

	$table->addRow([
		new CCheckBox('items['.$row['type'].':'.$row['id'].']', $row['type'].':'.$row['id']),
		$labels[$row['type']],
		[
			new CLink($row['name'], $object_url),
			$row['tech'] !== null ? [' ', (new CSpan('('.$row['tech'].')'))->addClass(ZBX_STYLE_GREY)] : null,
			$restored !== null
				? [' ', (new CSpan(sprintf(_('restored as ID %1$s'), $restored)))->addClass(ZBX_STYLE_GREY)]
				: null
		],
		$row['id'] === '0' ? '' : $row['id'],
		$state,
		new CLink(_('Download'), $url('configbackup.download', [
			'snapshotid' => $snapshotid, 'type' => $row['type'], 'id' => $row['id']
		]))
	]);
}

$mode_select = (new CSelect('mode'))
	->setValue('auto')
	->addOption(new CSelectOption('auto', _('Recreate if missing, skip if it exists')))
	->addOption(new CSelectOption('auto_parts', _('Recreate if missing; templates/hosts/maps also get missing parts back')))
	->addOption(new CSelectOption('overwrite', _('Overwrite with the snapshot version')));

$form->addItem([
	$table,
	$data['paging'],
	(new CDiv([
		(new CSpan(_('Selected')))->addClass('cb-bar-label'),
		$mode_select,
		(new CSimpleButton(_('Restore')))
			->setAttribute('data-cb-action', 'configbackup.restore')
			->setAttribute('data-cb-token', $data['csrf']['restore'])
			->setAttribute('data-cb-needs-selection', 'items')
			->setAttribute('data-cb-confirm', _('Restore the selected objects from this snapshot?')),
		(new CSpan(
			_('Dependencies go first (groups, templates, user groups...), and references to anything recreated in the same run follow it to its new ID.')
		))->addClass(ZBX_STYLE_GREY)
	]))->addClass('cb-bar')
]);

$html_page
	->addItem($form)
	->show();

$this->includeJsFile('configbackup.js.php');
