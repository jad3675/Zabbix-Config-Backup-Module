<?php declare(strict_types = 0);
/**
 * Shared view helpers. Views include this with require_once.
 */

if (!function_exists('cb_url')) {
	function cb_url(string $action, array $args = []): CUrl {
		$url = (new CUrl('zabbix.php'))->setArgument('action', $action);

		foreach ($args as $key => $value) {
			if ($value !== null && $value !== '') {
				$url->setArgument($key, $value);
			}
		}

		return $url;
	}

	/**
	 * Header buttons shared by the module's pages; the current one is left out.
	 */
	function cb_nav(string $current): CTag {
		$list = new CList();

		foreach ([
			'configbackup.list' => _('Snapshots'),
			'configbackup.schedules' => _('Schedules'),
			'configbackup.destinations' => _('Destinations'),
			'configbackup.settings' => _('Settings')
		] as $action => $label) {
			if ($action !== $current) {
				$list->addItem((new CRedirectButton($label, cb_url($action)))->addClass(ZBX_STYLE_BTN_ALT));
			}
		}

		return (new CTag('nav', true, $list))->setAttribute('aria-label', _('Content controls'));
	}

	function cb_state(string $text, string $state, ?string $hint = null): CSpan {
		$span = (new CSpan($text))->addClass('cb-state cb-state-'.$state);

		return $hint !== null ? $span->setTitle($hint) : $span;
	}

	function cb_ago(?int $time): string {
		return $time ? zbx_date2age($time).' '._('ago') : _('never');
	}

	/**
	 * Small POST form with one button, for row actions.
	 */
	function cb_post_button(string $label, string $action, string $token, array $vars, ?string $confirm = null,
			bool $alt = true): CForm {
		$form = (new CForm())->addClass('cb-inline-form');

		foreach ($vars as $name => $value) {
			$form->addVar($name, $value);
		}

		$button = (new CSimpleButton($label))
			->addClass($alt ? ZBX_STYLE_BTN_LINK : null)
			->setAttribute('data-cb-action', $action)
			->setAttribute('data-cb-token', $token);

		if ($confirm !== null) {
			$button->setAttribute('data-cb-confirm', $confirm);
		}

		return $form->addItem($button);
	}

	function cb_runner_line(array $runner): CDiv {
		if (!isset($runner['last_seen'])) {
			return (new CDiv([cb_state(_('Runner never ran'), 'missing'), ' ',
				_('Install the timer shown in Settings.')]))->addClass('cb-bar-label');
		}

		$parts = [
			$runner['alive'] ? cb_state(_('Runner OK'), 'same') : cb_state(_('Runner stopped'), 'missing'),
			' ',
			sprintf(_('last seen %1$s as %2$s on %3$s'), cb_ago($runner['last_seen']), $runner['user'], $runner['host'])
		];

		$out = [new CDiv($parts)];

		if (empty($runner['api_ok'])) {
			$out[] = (new CDiv([
				cb_state(_('API problem'), 'missing'), ' ',
				$runner['api_message'] ?? _('not checked yet'),
				isset($runner['api_checked'])
					? (new CSpan(' ('.sprintf(_('checked %1$s'), cb_ago($runner['api_checked'])).')'))->addClass(ZBX_STYLE_GREY)
					: null
			]))->addClass('cb-api-problem');
		}
		else {
			$parts[] = [', ', cb_state(_('API OK'), 'same', $runner['api_message'] ?? '')];
			$out = [new CDiv($parts)];
		}

		return (new CDiv($out))->addClass('cb-bar-label');
	}
}
