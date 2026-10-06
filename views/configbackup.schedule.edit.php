<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

use Modules\ConfigBackup\Lib\Schedule;

require_once __DIR__.'/inc/common.php';

$s = $data['schedule'];
$all = !$s['types'];

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), cb_url('configbackup.list')),
		new CLink(_('Schedules'), cb_url('configbackup.schedules')),
		$data['is_new'] ? _('New') : $s['name']
	])));

$every = (new CSelect('every'))->setId('every')->setValue($s['every'])
	->addOption(new CSelectOption('daily', _('Daily')))
	->addOption(new CSelectOption('weekly', _('Weekly')))
	->addOption(new CSelectOption('hours', _('Every N hours')));

$hours = (new CSelect('hours'))->setValue((string) $s['hours']);
foreach ([1, 2, 3, 4, 6, 8, 12] as $h) {
	$hours->addOption(new CSelectOption((string) $h, sprintf(_n('%1$s hour', '%1$s hours', $h), $h)));
}

$weekday = (new CSelect('weekday'))->setValue((string) $s['weekday']);
foreach (Schedule::days() as $i => $day) {
	$weekday->addOption(new CSelectOption((string) $i, _($day)));
}

$types = (new CDiv())->addClass('cb-types');
foreach ($data['sections'] as $section => $members) {
	$group = (new CDiv((new CDiv($section))->addClass('cb-types-title')))->addClass('cb-types-group');

	foreach ($members as $key => $label) {
		$group->addItem(new CDiv((new CCheckBox('types['.$key.']', $key))->setId('type_'.$key)->setLabel($label)
			->setChecked($all || in_array($key, $s['types'], true))
		));
	}

	$types->addItem($group);
}

$dests = new CDiv();
foreach ($data['destinations'] as $d) {
	$dests->addItem(new CDiv((new CCheckBox('destinations['.$d['id'].']', $d['id']))
		->setId('dest_'.$d['id'])
		->setLabel($d['name'].' ('.strtoupper($d['kind']).')'.($d['enabled'] ? '' : ' - '._('disabled')))
		->setChecked(in_array($d['id'], $s['destinations'], true))
	));
}

$form = (new CForm())->setName('cb_schedule')->addVar('op', 'save')->addVar('id', $data['is_new'] ? '' : $s['id']);

$form->addItem((new CFormGrid())
	->addItem([(new CLabel(_('Name'), 'name'))->setAsteriskMark(),
		new CFormField((new CTextBox('name', $s['name'], false, 128))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
	])
	->addItem([new CLabel(_('Enabled'), 'enabled'), new CFormField((new CCheckBox('enabled'))->setChecked($s['enabled']))])
	->addItem([new CLabel(_('Runs'), 'every'), new CFormField([
		$every, ' ',
		(new CSpan([_('every'), ' ', $hours, ' ', _('starting')]))->addClass('cb-when-hours'),
		(new CSpan([_('on'), ' ', $weekday]))->addClass('cb-when-weekly'),
		' ', _('at'), ' ',
		(new CTextBox('at', $s['at'], false, 5))->setWidth(ZBX_TEXTAREA_TINY_WIDTH)->setAttribute('placeholder', 'HH:MM'),
		(new CDiv([
			sprintf(_('Time zone %1$s'), $data['timezone']),
			$data['timezone_chosen'] ? '' : ' ('._('not chosen yet, set it in Settings').')',
			'. ', _('"Every 6 hours starting 02:30" runs at 02:30, 08:30, 14:30 and 20:30.')
		]))->addClass(ZBX_STYLE_GREY)
	])])
	->addItem([new CLabel(_('Object types')), new CFormField([
		new CDiv((new CCheckBox('all_types'))->setLabel(_('All (types added in later versions are included automatically)'))
			->setChecked($all)->setAttribute('data-cb-all', 'types')
		),
		$types
	])])
	->addItem([new CLabel(_('Send to')), new CFormField([
		$data['destinations'] ? $dests : (new CDiv(_('No destinations yet. Snapshots stay local.')))->addClass(ZBX_STYLE_GREY),
		(new CDiv(_('Every snapshot is also kept locally, where it can be browsed and restored.')))->addClass(ZBX_STYLE_GREY)
	])])
	->addItem([new CLabel(_('Keep locally')), new CFormField([
		(new CNumericBox('keep_count', $s['keep_count'], 6))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
		' ', _('newest from this schedule'), ' ',
		(new CNumericBox('keep_days', $s['keep_days'], 5))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
		' ', _('days at most (0 = no age limit)'),
		(new CDiv(_('Retention on destinations is set per destination.')))->addClass(ZBX_STYLE_GREY)
	])])
	->addItem(new CFormActions(
		(new CSimpleButton($data['is_new'] ? _('Add') : _('Update')))
			->setAttribute('data-cb-action', 'configbackup.schedule.update')
			->setAttribute('data-cb-token', $data['csrf']),
		[new CRedirectButton(_('Cancel'), cb_url('configbackup.schedules'))]
	))
);

$html_page->addItem($form)->show();

$this->includeJsFile('configbackup.js.php');
?>
<script>
(function () {
	const every = document.querySelector('[name="every"]');
	const sync = function () {
		const v = every.value;
		document.querySelector('.cb-when-hours').hidden = v !== 'hours';
		document.querySelector('.cb-when-weekly').hidden = v !== 'weekly';
	};
	every.addEventListener('change', sync);
	sync();
})();
</script>
