<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

use Modules\ConfigBackup\Lib\Diff;
use Modules\ConfigBackup\Lib\Restorer;
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

$meta = $data['meta'];
$def = $data['def'];
$object = $data['object'];
$snapshotid = $meta['id'];
$taken = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $meta['created']);
$is_export = $def['mode'] === Types::MODE_EXPORT;
$is_singleton = $def['mode'] === Types::MODE_SINGLETON;

$state_text = [
	'missing' => _('Missing: not in the live system'),
	'recreated' => sprintf(_('Recreated: exists under ID %1$s'), $data['live_id']),
	'changed' => _('Changed since the snapshot'),
	'same' => _('Identical to the live system')
];

$html_page = (new CHtmlPage())
	->setTitle($def['label'].': '.$object['name'])
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), $url('configbackup.list')),
		new CLink($meta['label'] !== '' ? $meta['label'] : $taken,
			$url('configbackup.snapshot', ['snapshotid' => $snapshotid])
		),
		$object['name']
	])));

if ($data['error'] !== null) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]],
		_('Cannot read the live object'), false
	));
}

/*
 * Restore form.
 */
$mode_info = [
	Restorer::MODE_CREATE => [
		'label' => $is_export ? _('Import as a copy') : _('Recreate'),
		'help' => $is_export
			? _('Imports the snapshot under the new name below, next to the original. Expressions, graphs and dashboards that refer to the old name are rewritten to the new one.')
			: _('Creates the object again. It gets a new ID; anything restored later from this snapshot that refers to it follows the new ID. With a new name below it becomes a copy next to the original.'),
		'confirm' => _('Create this object from the snapshot?'),
		'rename' => true
	],
	Restorer::MODE_OVERWRITE => [
		'label' => _('Overwrite current'),
		'help' => $is_export
			? _('Updates the live object to the snapshot version and recreates anything missing. Items, triggers and other parts added since the snapshot are kept.')
			: _('Replaces the live configuration with the snapshot version. If the object was recreated by hand under a different ID, that one is overwritten.'),
		'confirm' => _('Overwrite the live configuration with the snapshot version?'),
		'rename' => false
	],
	Restorer::MODE_MISSING => [
		'label' => _('Restore missing parts'),
		'help' => _('Creates the object if it is gone, and any deleted items, triggers, graphs, discovery rules, web scenarios and value maps. Nothing that exists is touched.'),
		'confirm' => _('Restore missing parts from the snapshot?'),
		'rename' => false
	],
	Restorer::MODE_EXACT => [
		'label' => _('Exact rollback'),
		'help' => _('Overwrites, and also deletes items, triggers, graphs, discovery rules, web scenarios, value maps, dashboards and template links added since the snapshot. History of deleted items is lost.'),
		'confirm' => _('Roll back exactly? Anything added since the snapshot is DELETED, including the history of deleted items.'),
		'rename' => false
	]
];

$modes = $data['modes'];

if ($data['state'] === 'missing') {
	$default_mode = $is_export ? Restorer::MODE_MISSING : Restorer::MODE_CREATE;
	$modes = array_values(array_diff($modes, [Restorer::MODE_OVERWRITE, Restorer::MODE_EXACT]));
}
elseif ($is_singleton) {
	$default_mode = Restorer::MODE_OVERWRITE;
}
else {
	$default_mode = $is_export ? Restorer::MODE_MISSING : Restorer::MODE_OVERWRITE;
}

$mode_list = new CDiv();
$mode_help = [];

foreach ($modes as $mode) {
	$radio = (new CInput('radio', 'mode', $mode))->setId('mode_'.$mode);

	if ($mode === $default_mode) {
		$radio->setAttribute('checked', 'checked');
	}

	if ($mode_info[$mode]['rename']) {
		$radio->setAttribute('data-cb-rename', '');
	}

	$radio->setAttribute('data-cb-confirm', $mode_info[$mode]['confirm']);

	$mode_list->addItem(new CTag('label', true, [$radio, ' ', $mode_info[$mode]['label']]));
	$mode_help[] = (new CDiv($mode_info[$mode]['help']))
		->addClass('cb-mode-help')
		->setAttribute('data-mode', $mode);
}
$mode_list->addClass('cb-mode-list');

$form = (new CForm())
	->setName('cb_restore')
	->addVar('snapshotid', $snapshotid)
	->addVar('items[]', $data['type'].':'.$object['id'])
	->addVar('back', 'object');

$grid = (new CFormGrid())
	->addItem([
		new CLabel(_('Taken from')),
		new CFormField([
			new CLink($meta['label'] !== '' ? $meta['label'].' ('.$taken.')' : $taken,
				$url('configbackup.snapshot', ['snapshotid' => $snapshotid])
			),
			$is_singleton ? null : (new CSpan(' '.sprintf(_('ID %1$s at the time'), $object['id'])))->addClass(ZBX_STYLE_GREY)
		])
	])
	->addItem([
		new CLabel(_('Now')),
		new CFormField((new CSpan($state_text[$data['state']]))->addClass('cb-state cb-state-'.$data['state']))
	]);

if ($def['secrets'] !== null) {
	$grid->addItem([
		new CLabel(_('Not in backups')),
		new CFormField((new CDiv($def['secrets']))->addClass('cb-note'))
	]);
}

$grid
	->addItem([new CLabel(_('Restore')), new CFormField([$mode_list, $mode_help])]);

if (!$is_singleton) {
	$grid->addItem([
		new CLabel(_('New name'), 'new_name'),
		(new CFormField([
			(new CTextBox('new_name', ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', _('leave empty to keep').' "'.$object['name'].'"'),
		]))->addClass('cb-rename')
	]);
}

$grid->addItem(new CFormActions(
	(new CSimpleButton(_('Restore')))
		->setAttribute('data-cb-action', 'configbackup.restore')
		->setAttribute('data-cb-token', $data['csrf']['restore']),
	[
		new CRedirectButton(_('Download JSON'), $url('configbackup.download', [
			'snapshotid' => $snapshotid, 'type' => $data['type'], 'id' => $object['id']
		]))
	]
));

$form->addItem($grid);
$html_page->addItem($form);

/*
 * Single pieces of a template or host.
 */
if ($data['parts'] && $data['state'] !== 'missing') {
	$kinds = Modules\ConfigBackup\Lib\Parts::KINDS;
	$counts = array_count_values(array_filter(array_column($data['parts'], 'state')));
	$shown = $data['show_all_parts']
		? $data['parts']
		: array_filter($data['parts'], static fn($p) => $p['state'] !== 'same');

	$labels = ['missing' => _('Missing'), 'changed' => _('Changed'), 'same' => _('Unchanged')];

	$parts_form = (new CForm())
		->setName('cb_parts')
		->addVar('snapshotid', $meta['id'])
		->addVar('type', $data['type'])
		->addVar('id', $object['id']);

	$table = (new CTableInfo())
		->setHeader([
			(new CColHeader((new CCheckBox('all_parts_box'))->setAttribute('data-cb-all', 'parts')))
				->addClass(ZBX_STYLE_CELL_WIDTH),
			_('Part'), _('Name'), _('Key / detail'), _('Now')
		])
		->setNoDataMessage(_('Every item, trigger, graph, discovery rule, dashboard and macro matches the snapshot.'));

	foreach ($shown as $p) {
		$table->addRow([
			new CCheckBox('parts['.md5($p['key']).']', $p['key']),
			_($kinds[$p['kind']]),
			$p['name'],
			(new CCol((new CSpan(mb_strimwidth($p['detail'], 0, 120, '…')))->addClass('cb-mono')->setTitle($p['detail'])))
				->addClass(ZBX_STYLE_WORDBREAK),
			$p['state'] !== null ? (new CSpan($labels[$p['state']]))->addClass('cb-state cb-state-'.$p['state']) : ''
		]);
	}

	$summary = [];
	foreach ($labels as $state => $label) {
		if (!empty($counts[$state])) {
			$summary[] = (new CSpan($label.': '.$counts[$state]))->addClass('cb-state cb-state-'.$state);
			$summary[] = ' ';
		}
	}

	$parts_form->addItem([
		(new CDiv(_('Restore single parts')))->addClass('cb-section-title'),
		(new CDiv([
			$summary,
			(new CSpan())->addClass('cb-spacer'),
			$data['show_all_parts']
				? new CLink(_('Show only missing and changed'), cb_url('configbackup.object', [
					'snapshotid' => $meta['id'], 'type' => $data['type'], 'id' => $object['id']
				]))
				: new CLink(sprintf(_('Show all %1$d parts'), count($data['parts'])), cb_url('configbackup.object', [
					'snapshotid' => $meta['id'], 'type' => $data['type'], 'id' => $object['id'], 'all_parts' => 1
				]))
		]))->addClass('cb-bar'),
		$table,
		(new CDiv([
			(new CSpan(_('Selected')))->addClass('cb-bar-label'),
			(new CSelect('mode'))
				->setValue('missing')
				->addOption(new CSelectOption('missing', _('Restore if missing, leave existing ones alone')))
				->addOption(new CSelectOption('overwrite', _('Restore, and overwrite changed ones with the snapshot version'))),
			(new CSimpleButton(_('Restore selected')))
				->setAttribute('data-cb-action', 'configbackup.restore.parts')
				->setAttribute('data-cb-token', $data['csrf']['parts'])
				->setAttribute('data-cb-needs-selection', 'parts')
				->setAttribute('data-cb-confirm', _('Restore the selected parts from the snapshot?')),
			(new CSpan(_('The template or host itself is not touched. Items a trigger or graph needs come back with it if they are missing, and other triggers\' dependencies on a restored trigger are relinked.')))
				->addClass(ZBX_STYLE_GREY)
		]))->addClass('cb-bar')
	]);

	$html_page->addItem($parts_form);
}

/*
 * What a restore would change.
 */
if ($data['state'] === 'changed' || ($data['state'] === 'recreated' && $data['hunks'])) {
	$diff = (new CDiv())->addClass('cb-diff');

	foreach ($data['hunks'] as [$op, $line]) {
		if ($op === '…') {
			$diff->addItem((new CDiv(sprintf(_('… %1$d unchanged lines'), $line)))->addClass('cb-skip'));
		}
		else {
			$diff->addItem((new CDiv($op.' '.$line))->addClass(
				$op === Diff::ADD ? 'cb-add' : ($op === Diff::DEL ? 'cb-del' : null)
			));
		}
	}

	$html_page->addItem([
		(new CDiv(_('What a restore would change')))->addClass('cb-section-title'),
		(new CDiv([
			(new CSpan('- '._('current configuration')))->addClass('cb-state cb-state-missing'),
			(new CSpan('+ '._('snapshot')))->addClass('cb-state cb-state-same'),
			(new CSpan($is_export
				? _('Compared as Zabbix export files.')
				: _('IDs and runtime state are left out of the comparison.')
			))->addClass(ZBX_STYLE_GREY)
		]))->addClass('cb-legend cb-bar'),
		$diff
	]);
}
elseif ($data['state'] === 'recreated') {
	$html_page->addItem((new CDiv(
		_('The recreated object matches the snapshot, apart from its ID.')
	))->addClass('cb-bar'));
}

$html_page->addItem((new CTag('details', true, [
	new CTag('summary', true, _('Snapshot content')),
	(new CPre($data['snap_text']))->addClass('cb-json')
]))->addClass('cb-details'));

$html_page->show();

$this->includeJsFile('configbackup.js.php');
