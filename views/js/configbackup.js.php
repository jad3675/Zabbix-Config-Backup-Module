<?php
/**
 * Shared behaviour for all Config backup pages.
 *
 * <button data-cb-action="configbackup.x" data-cb-token="..." data-cb-confirm="...">: submits its form as that
 * action with that CSRF token. Needed because one form carries several actions and Zabbix checks the token
 * against the exact action name.
 *
 * <input type="checkbox" data-cb-all="name">: ticks every checkbox named name[] in the same form.
 */
?>
<script>
(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		const button = e.target.closest('[data-cb-action]');

		if (button === null) {
			return;
		}

		e.preventDefault();

		const form = button.closest('form');

		if (button.dataset.cbNeedsSelection !== undefined
				&& form.querySelectorAll('input[name^="' + button.dataset.cbNeedsSelection + '["]:checked').length == 0) {
			alert(<?= json_encode(_('Select at least one row first.')) ?>);

			return;
		}

		if (button.dataset.cbConfirm && !confirm(button.dataset.cbConfirm)) {
			return;
		}

		const set = function (name, value) {
			let input = form.querySelector('input[type="hidden"][name="' + name + '"]');

			if (input === null) {
				input = document.createElement('input');
				input.type = 'hidden';
				input.name = name;
				form.appendChild(input);
			}

			input.value = value;
		};

		set('action', button.dataset.cbAction);

		if (button.dataset.cbOp !== undefined) {
			set('op', button.dataset.cbOp);
		}
		set(<?= json_encode(CSRF_TOKEN_NAME) ?>, button.dataset.cbToken);

		form.method = 'post';
		form.action = 'zabbix.php';
		button.classList.add('is-loading');
		document.querySelectorAll('[data-cb-action]').forEach(function (b) { b.disabled = true; });
		form.submit();
	});

	document.addEventListener('change', function (e) {
		const all = e.target.closest('[data-cb-all]');

		if (all !== null) {
			all.closest('form').querySelectorAll('input[name^="' + all.dataset.cbAll + '["]').forEach(function (box) {
				box.checked = all.checked;
			});
		}

		const mode = e.target.closest('input[name="mode"]');

		if (mode !== null) {
			cbSyncMode(mode.closest('form'));
		}
	});

	window.cbSyncMode = function (form) {
		if (form === null) {
			return;
		}

		const checked = form.querySelector('input[name="mode"]:checked');

		if (checked === null) {
			return;
		}

		form.querySelectorAll('.cb-mode-help').forEach(function (help) {
			help.hidden = help.dataset.mode !== checked.value;
		});

		const button = form.querySelector('[data-cb-action="configbackup.restore"]');
		if (button !== null && checked.dataset.cbConfirm) {
			button.dataset.cbConfirm = checked.dataset.cbConfirm;
		}

		const name = form.querySelector('[name="new_name"]');

		if (name !== null) {
			const allowed = checked.dataset.cbRename !== undefined;
			name.disabled = !allowed;
			name.closest('.cb-rename').classList.toggle('cb-muted', !allowed);
		}
	};

	document.querySelectorAll('form').forEach(function (form) { cbSyncMode(form); });
})();
</script>
