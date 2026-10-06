<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

use Modules\ConfigBackup\Lib\Schedule;

require_once __DIR__.'/inc/common.php';

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(cb_nav('configbackup.schedules'))
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), cb_url('configbackup.list')), _('Schedules')
	])));

if ($data['storage_problem'] !== null) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['storage_problem']]], null, false));
}

$html_page->addItem((new CDiv([
	new CRedirectButton(_('New schedule'), cb_url('configbackup.schedule.edit')),
	(new CSpan())->addClass('cb-spacer'),
	cb_runner_line($data['runner'])
]))->addClass('cb-bar'));

$dest_names = array_column($data['settings']['destinations'], 'name', 'id');
$tz = Modules\ConfigBackup\Lib\Settings::timezone($data['settings']);

if ($data['settings']['timezone'] === '') {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [['message' =>
		_('No schedule time zone is chosen yet, so times below are UTC. Pick one in Settings.')
	]], null, false));
}

$table = (new CTableInfo())->setHeader([
	_('Name'), _('When'), _('Objects'), _('Send to'), _('Keep locally'), sprintf(_('Next run (%1$s)'), $tz), _('Last run'), _('Status'), ''
]);

foreach ($data['settings']['schedules'] as $s) {
	$st = $data['state'][$s['id']] ?? [];

	$last = isset($st['last_run'])
		? [
			($st['last_ok'] ?? false) ? cb_state(_('OK'), 'same') : cb_state(_('Failed'), 'missing'),
			' ', (new CSpan(cb_ago($st['last_run'])))->setTitle($st['last_message'] ?? '')
		]
		: (new CSpan(isset($st['armed'])
			? sprintf(_('not yet (armed %1$s)'), Schedule::format($st['armed'], $tz, 'm-d H:i'))
			: _('not yet (waiting for the runner)')
		))->addClass(ZBX_STYLE_GREY);

	if (($st['retry_after'] ?? 0) > time()) {
		$last = [$last, ' ', (new CSpan(sprintf(_('retry at %1$s'), date('H:i', $st['retry_after']))))->addClass(ZBX_STYLE_GREY)];
	}

	$table->addRow([
		new CLink($s['name'], cb_url('configbackup.schedule.edit', ['id' => $s['id']])),
		Schedule::describe($s),
		$s['types'] ? sprintf(_('%1$d types'), count($s['types'])) : _('All'),
		$s['destinations']
			? implode(', ', array_map(static fn($id) => $dest_names[$id] ?? '?', $s['destinations']))
			: (new CSpan(_('local only')))->addClass(ZBX_STYLE_GREY),
		$s['keep_count'].($s['keep_days'] ? ' / '.$s['keep_days'].'d' : ''),
		$s['enabled'] ? Schedule::format(Schedule::nextSlot($s, time(), $tz), $tz) : '',
		$last,
		cb_post_button($s['enabled'] ? _('Enabled') : _('Disabled'), 'configbackup.schedule.update',
			$data['csrf']['update'], ['op' => 'toggle', 'id' => $s['id']]
		)->addClass($s['enabled'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
		[
			cb_post_button(_('Run now'), 'configbackup.queue', $data['csrf']['queue'],
				['kind' => 'run', 'schedule' => $s['id'], 'back' => 'schedules']
			),
			' ',
			cb_post_button(_('Delete'), 'configbackup.schedule.update', $data['csrf']['update'],
				['op' => 'delete', 'id' => $s['id']], _('Delete this schedule? Its snapshots are kept.')
			)
		]
	]);
}

$log = (new CTag('details', true, [
	new CTag('summary', true, _('Runner log (last 40 lines)')),
	(new CPre($data['log'] ? implode("\n", array_reverse($data['log'])) : _('Empty. The runner logs every decision it makes here and in .state/runner.log.')))
		->addClass('cb-code')
]))->addClass('cb-details');

$html_page->addItem($table)->addItem($log)->addItem((new CDiv(
	sprintf(_('Times are in %1$s (Settings).'), $tz).' '._('A slot missed while the runner was down runs once when it is back. A failed run retries after 5, 10, 20 minutes, up to hourly. Each schedule keeps its own snapshots: retention here never touches manual snapshots or other schedules\'.')
))->addClass('cb-note cb-bar-label'))->show();

$this->includeJsFile('configbackup.js.php');
