<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

require_once __DIR__.'/inc/common.php';

$d = $data['d'];
$kind = $d['kind'];
$st = $data['state'];

$html_page = (new CHtmlPage())
	->setTitle($data['title'].': '.['s3' => 'S3', 'sftp' => 'SFTP', 'git' => 'Git'][$kind])
	->setNavigation((new CList())->addItem(new CBreadcrumbs([
		new CLink(_('Config backup'), cb_url('configbackup.list')),
		new CLink(_('Destinations'), cb_url('configbackup.destinations')),
		$data['is_new'] ? _('New') : $d['name']
	])));

$text = static fn(string $name, int $width = ZBX_TEXTAREA_STANDARD_WIDTH, string $placeholder = '') =>
	(new CTextBox($name, (string) $d[$name], false, 2048))->setWidth($width)->setAttribute('placeholder', $placeholder);

$secret = static function (string $name, string $hint = '', bool $multiline = false) use ($data): array {
	$set = !empty($data['secret_set'][$name]);
	$field = $multiline
		? (new CTextArea($name, ''))->setWidth(ZBX_TEXTAREA_BIG_WIDTH)->setRows(4)
			->setAttribute('placeholder', $set ? _('stored; paste a new one to replace it') : $hint)
			->setAttribute('autocomplete', 'off')->setAttribute('spellcheck', 'false')
		: (new CPassBox($name, '', 2048))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', $set ? _('stored; type to replace') : $hint)
			->setAttribute('autocomplete', 'new-password');

	return [$field, $set ? new CDiv((new CCheckBox('clear['.$name.']', $name))->setId('clear_'.$name)->setLabel(_('Remove the stored value'))) : null];
};

$grey = static fn(string $t) => (new CDiv($t))->addClass(ZBX_STYLE_GREY);

$grid = (new CFormGrid())
	->addItem([(new CLabel(_('Name'), 'name'))->setAsteriskMark(), new CFormField($text('name'))])
	->addItem([new CLabel(_('Enabled'), 'enabled'), new CFormField((new CCheckBox('enabled'))->setChecked($d['enabled']))]);

if ($kind !== 'git') {
	$grid->addItem([new CLabel(_('Back up now'), 'manual'), new CFormField([
		(new CCheckBox('manual'))->setChecked($d['manual']),
		$grey(_('Ticked by default under Back up now, so manual snapshots go here too.'))
	])]);
}

switch ($kind) {
	case 's3':
		$grid
			->addItem([new CLabel(_('Endpoint'), 'endpoint'), new CFormField([
				$text('endpoint', ZBX_TEXTAREA_BIG_WIDTH, 'https://s3.us-east-1.amazonaws.com'),
				$grey(_('Empty for AWS. For MinIO, Wasabi, Backblaze B2, Ceph and others: their S3 URL.'))
			])])
			->addItem([new CLabel(_('Region'), 'region'), new CFormField($text('region', ZBX_TEXTAREA_SMALL_WIDTH))])
			->addItem([(new CLabel(_('Bucket'), 'bucket'))->setAsteriskMark(), new CFormField($text('bucket'))])
			->addItem([new CLabel(_('Prefix'), 'prefix'), new CFormField([$text('prefix'),
				$grey(_('Folder inside the bucket. Use one per Zabbix instance.'))
			])])
			->addItem([new CLabel(_('Path-style URLs'), 'path_style'), new CFormField([
				(new CCheckBox('path_style'))->setChecked($d['path_style']),
				$grey(_('Needed by most self-hosted S3 (MinIO, Ceph). Leave off for AWS.'))
			])])
			->addItem([(new CLabel(_('Access key'), 'access_key'))->setAsteriskMark(), new CFormField($text('access_key'))])
			->addItem([new CLabel(_('Secret key'), 'secret_key'), new CFormField($secret('secret_key'))])
			->addItem([new CLabel(_('Server-side encryption'), 'sse'), new CFormField(
				(new CSelect('sse'))->setValue($d['sse'])
					->addOption(new CSelectOption('', _('Bucket default')))
					->addOption(new CSelectOption('AES256', 'AES256'))
					->addOption(new CSelectOption('aws:kms', 'aws:kms'))
			)])
			->addItem([new CLabel(_('Tip')), new CFormField($grey(
				_('Turn on versioning or Object Lock on the bucket and give this key PutObject/GetObject/ListBucket/DeleteObject on the prefix only. Then a compromised Zabbix server cannot destroy its own history.')
			))]);
		break;

	case 'sftp':
		$grid
			->addItem([(new CLabel(_('Host'), 'host'))->setAsteriskMark(), new CFormField([
				$text('host'), ' ', _('port'), ' ',
				(new CNumericBox('port', $d['port'], 5))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			])])
			->addItem([(new CLabel(_('User name'), 'username'))->setAsteriskMark(), new CFormField($text('username'))])
			->addItem([new CLabel(_('Private key'), 'ssh_key'), new CFormField([
				$secret('ssh_key', "-----BEGIN OPENSSH PRIVATE KEY-----\n...", true),
				$grey(_('OpenSSH or PEM. Preferred over a password.'))
			])])
			->addItem([new CLabel(_('Key passphrase'), 'ssh_key_passphrase'), new CFormField($secret('ssh_key_passphrase'))])
			->addItem([new CLabel(_('Password'), 'password'), new CFormField($secret('password', _('only if no key')))])
			->addItem([new CLabel(_('Host key'), 'host_key'), new CFormField([
				$text('host_key', ZBX_TEXTAREA_BIG_WIDTH, 'SHA256:...'),
				$grey(_('Fingerprint as shown by ssh-keygen -lf. Empty: the first key seen is trusted and then required (like ssh accept-new).')),
				!empty($st['host_key_seen'])
					? new CDiv([_('Seen by the runner').': ', (new CSpan($st['host_key_seen']))->addClass('cb-mono')])
					: null
			])])
			->addItem([new CLabel(_('Directory'), 'path'), new CFormField([$text('path'),
				$grey(_('Relative to the login directory, or absolute. Created if missing.'))
			])]);
		break;

	case 'git':
		if (!$data['git_available']) {
			$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING,
				[['message' => _('The git command is not installed on this server. Install it (apt/dnf install git) before using this destination.')]],
				null, false
			));
		}

		$auth = (new CRadioButtonList('git_auth', $d['git_auth']))->setModern()
			->addValue(_('SSH deploy key'), 'ssh')
			->addValue(_('HTTPS token'), 'https');

		$grid
			->addItem([(new CLabel(_('Repository URL'), 'url'))->setAsteriskMark(), new CFormField([
				$text('url', ZBX_TEXTAREA_BIG_WIDTH, 'git@gitlab.example.com:noc/zabbix-config.git'),
				$grey(_('Use a private repository. Snapshots hold e-mail addresses, SNMP communities and webhook URLs.'))
			])])
			->addItem([(new CLabel(_('Branch'), 'branch'))->setAsteriskMark(), new CFormField($text('branch', ZBX_TEXTAREA_SMALL_WIDTH))])
			->addItem([new CLabel(_('Authentication')), new CFormField($auth)])
			->addItem([new CLabel(_('Deploy key'), 'ssh_key'), (new CFormField([
				$secret('ssh_key', "-----BEGIN OPENSSH PRIVATE KEY-----\n...", true),
				$grey(_('Give the key write access to this repository only.'))
			]))->addClass('cb-git-ssh')])
			->addItem([new CLabel(_('Host key line'), 'known_hosts'), (new CFormField([
				(new CTextArea('known_hosts', $d['known_hosts']))->setWidth(ZBX_TEXTAREA_BIG_WIDTH)->setRows(2),
				$grey(_('Output of ssh-keyscan for the Git host. Empty: the first key seen is trusted (accept-new).'))
			]))->addClass('cb-git-ssh')])
			->addItem([new CLabel(_('User name'), 'git_user'), (new CFormField([$text('git_user'),
				$grey(_('GitHub: any name. GitLab: oauth2 or the token name.'))
			]))->addClass('cb-git-https')])
			->addItem([new CLabel(_('Token'), 'git_token'), (new CFormField($secret('git_token')))->addClass('cb-git-https')])
			->addItem([new CLabel(_('Commit author')), new CFormField([$text('author_name'), ' ', $text('author_email')])])
			->addItem([new CLabel(_('Leave out people'), 'git_exclude_people'), new CFormField([
				(new CCheckBox('git_exclude_people'))->setChecked($d['git_exclude_people']),
				$grey(_('Users, user groups and media types stay out of the repository.'))
			])]);
		break;
}

if ($kind !== 'git') {
	$grid
		->addItem([new CLabel(_('Keep')), new CFormField([
			(new CNumericBox('keep_count', $d['keep_count'], 6))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
			' ', _('newest'), ' ',
			(new CNumericBox('keep_days', $d['keep_days'], 5))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
			' ', _('days at most (0 = no age limit)'),
			$grey(_('Counted per schedule (and manual), so a frequent light schedule never pushes out the full ones. Only files named like snapshots are ever deleted. With Object Lock the bucket may refuse; that is the point.'))
		])])
		->addItem([new CLabel(_('Encrypt with public key'), 'public_key'), new CFormField([
			(new CTextArea('public_key', $d['public_key']))->setWidth(ZBX_TEXTAREA_BIG_WIDTH)->setRows(5)
				->setAttribute('placeholder', "-----BEGIN PUBLIC KEY-----\n...")->setAttribute('spellcheck', 'false'),
			$data['key_fingerprint'] !== null ? new CDiv([_('Fingerprint').': ', (new CSpan($data['key_fingerprint']))->addClass('cb-mono')]) : null,
			$grey(_('Strongly recommended. Create a pair with "zbx-config-backup.php keygen private.pem public.pem" on your own machine, paste public.pem here and keep private.pem somewhere else. Without the private key nobody can read these copies, including you.'))
		])])
		->addItem([new CLabel(_('Private key path'), 'private_key_path'), new CFormField([
			$text('private_key_path', ZBX_TEXTAREA_BIG_WIDTH, '/etc/zabbix/configbackup-restore.pem'),
			$grey(sprintf(_('Optional. A private key file on this server, readable by %1$s, lets "Pull" work from the frontend. Leaving it empty is safer: pull with the CLI and --key when you need to.'), $data['process_user']))
		])]);
}

$grid->addItem(new CFormActions(
	(new CSimpleButton($data['is_new'] ? _('Add') : _('Update')))
		->setAttribute('data-cb-action', 'configbackup.destination.update')
		->setAttribute('data-cb-token', $data['csrf']),
	[new CRedirectButton(_('Cancel'), cb_url('configbackup.destinations'))]
));

$form = (new CForm())
	->setName('cb_destination')
	->addVar('op', 'save')
	->addVar('id', $data['is_new'] ? '' : $d['id'])
	->addVar('kind', $kind)
	->addItem($grid);

if (!$data['is_new'] && !empty($st)) {
	$lines = [];
	foreach ([['test', _('Last test')], ['last', _('Last send')]] as [$p, $label]) {
		if (isset($st[$p.'_time'])) {
			$lines[] = new CDiv([$label.': ', ($st[$p.'_ok'] ?? false) ? cb_state(_('OK'), 'same') : cb_state(_('Failed'), 'missing'),
				' ', cb_ago($st[$p.'_time']), ', ', $st[$p.'_message'] ?? ''
			]);
		}
	}

	$html_page->addItem((new CDiv($lines))->addClass('cb-bar cb-status'));
}

$html_page->addItem($form)->show();

$this->includeJsFile('configbackup.js.php');
?>
<script>
(function () {
	const sync = function () {
		const checked = document.querySelector('input[name="git_auth"]:checked');

		if (checked === null) {
			return;
		}

		document.querySelectorAll('.cb-git-ssh').forEach(function (el) {
			const row = el.previousElementSibling;
			el.hidden = checked.value !== 'ssh';
			if (row) row.hidden = el.hidden;
		});
		document.querySelectorAll('.cb-git-https').forEach(function (el) {
			const row = el.previousElementSibling;
			el.hidden = checked.value !== 'https';
			if (row) row.hidden = el.hidden;
		});
	};
	document.querySelectorAll('input[name="git_auth"]').forEach(function (r) { r.addEventListener('change', sync); });
	sync();
})();
</script>
