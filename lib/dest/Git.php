<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib\Dest;

use Modules\ConfigBackup\Lib\Restorer;
use Modules\ConfigBackup\Lib\Store;
use Modules\ConfigBackup\Lib\Types;

/**
 * Not a backup target but a change history: each snapshot is written out as one readable JSON file per object
 * (IDs and runtime noise stripped, keys sorted) and committed only if something changed. Your Git host then
 * shows who-changed-what as ordinary diffs.
 *
 * Needs the git binary. Auth: SSH deploy key, or HTTPS with a token.
 */
class Git {

	private array $d;
	private string $workdir;
	private string $secret;
	private array $env = [];
	private array $cleanup = [];

	public function __construct(array $d, string $workdir, string $secret) {
		if ($d['url'] === '') {
			throw new \InvalidArgumentException('Git destination needs a repository URL.');
		}

		$this->d = $d;
		$this->workdir = $workdir;
		$this->secret = $secret;
	}

	public static function available(): bool {
		return trim((string) @shell_exec('command -v git 2>/dev/null')) !== '';
	}

	private function prepareAuth(): void {
		$this->env = [
			'GIT_TERMINAL_PROMPT' => '0',
			'HOME' => $this->workdir.'.home',
			'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
			'LANG' => 'C'
		];

		if (!is_dir($this->env['HOME'])) {
			mkdir($this->env['HOME'], 0700, true);
		}

		if ($this->d['git_auth'] === 'ssh') {
			$key = $this->env['HOME'].'/id';
			$known = $this->env['HOME'].'/known_hosts';
			$old = umask(0077);

			if ($this->secret !== '') {
				file_put_contents($key, rtrim(str_replace("\r", '', $this->secret))."\n");
				$this->cleanup[] = $key;
			}

			if (trim($this->d['known_hosts']) !== '') {
				file_put_contents($known, trim($this->d['known_hosts'])."\n");
			}

			umask($old);

			$this->env['GIT_SSH_COMMAND'] = sprintf(
				'ssh %s-o IdentitiesOnly=yes -o BatchMode=yes -o UserKnownHostsFile=%s -o StrictHostKeyChecking=%s',
				$this->secret !== '' ? '-i '.escapeshellarg($key).' ' : '',
				escapeshellarg($known),
				trim($this->d['known_hosts']) !== '' ? 'yes' : 'accept-new'
			);
		}
		else {
			$askpass = $this->env['HOME'].'/askpass.sh';
			file_put_contents($askpass, "#!/bin/sh\ncase \"\$1\" in Username*) echo \"\$CB_GIT_USER\";; *) echo \"\$CB_GIT_TOKEN\";; esac\n");
			chmod($askpass, 0700);
			$this->env['GIT_ASKPASS'] = $askpass;
			$this->env['CB_GIT_USER'] = $this->d['git_user'] !== '' ? $this->d['git_user'] : 'oauth2';
			$this->env['CB_GIT_TOKEN'] = $this->secret;
		}
	}

	private function git(array $args, bool $check = true): array {
		$cmd = array_merge(['git', '-C', $this->workdir], $args);
		$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->env);

		if (!is_resource($proc)) {
			throw new \RuntimeException('Cannot run git.');
		}

		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);

		if ($check && $code != 0) {
			$text = trim($err !== '' ? $err : $out);
			// Never echo a token back.
			$text = $this->secret !== '' ? str_replace($this->secret, '***', $text) : $text;

			throw new \RuntimeException(sprintf('git %s: %s', $args[0], mb_substr($text, 0, 400)));
		}

		return [$code, $out];
	}

	private function sync(): bool {
		if (!is_dir($this->workdir.'/.git')) {
			if (!is_dir($this->workdir)) {
				mkdir($this->workdir, 0700, true);
			}

			$this->git(['init', '-q']);
		}

		[$code] = $this->git(['remote', 'get-url', 'origin'], false);
		$this->git($code == 0
			? ['remote', 'set-url', 'origin', $this->d['url']]
			: ['remote', 'add', 'origin', $this->d['url']]
		);

		$branch = $this->d['branch'];
		$this->git(['fetch', '-q', 'origin']);
		[$code] = $this->git(['rev-parse', '-q', '--verify', 'refs/remotes/origin/'.$branch], false);

		if ($code == 0) {
			$this->git(['checkout', '-q', '-B', $branch, 'origin/'.$branch]);
			$this->git(['reset', '-q', '--hard', 'origin/'.$branch]);

			return true;
		}

		// Empty repository or new branch.
		$this->git(['checkout', '-q', '--orphan', $branch], false);
		$this->git(['symbolic-ref', 'HEAD', 'refs/heads/'.$branch]);

		return false;
	}

	public function test(): string {
		$this->prepareAuth();

		try {
			if (!is_dir($this->workdir.'/.git')) {
				if (!is_dir($this->workdir)) {
					mkdir($this->workdir, 0700, true);
				}

				$this->git(['init', '-q']);
			}

			[$code] = $this->git(['remote', 'get-url', 'origin'], false);
			$this->git($code == 0
				? ['remote', 'set-url', 'origin', $this->d['url']]
				: ['remote', 'add', 'origin', $this->d['url']]
			);
			[, $out] = $this->git(['ls-remote', '--heads', 'origin']);
		}
		finally {
			$this->dropSecrets();
		}

		$branches = array_map(static fn($l) => preg_replace('~^.*refs/heads/~', '', $l),
			array_filter(explode("\n", trim($out)))
		);

		return sprintf('Repository reachable, %d branch(es)%s. Push rights are checked on the first publish.',
			count($branches), in_array($this->d['branch'], $branches, true) ? ', "'.$this->d['branch'].'" exists' : ''
		);
	}

	/**
	 * @return string  what happened, for the log
	 */
	public function publish(Store $store, string $snapshotid): string {
		$this->prepareAuth();

		try {
			$had_branch = $this->sync();
			$this->writeTree($store, $snapshotid);
			$this->git(['add', '-A']);

			[$code] = $this->git(['diff', '--cached', '--quiet'], false);

			if ($code == 0) {
				return 'No configuration changes since the last commit.';
			}

			[, $status] = $this->git(['diff', '--cached', '--name-status']);
			$message = self::commitMessage($status, $store->getMeta($snapshotid));

			$this->git(['-c', 'user.name='.$this->d['author_name'], '-c', 'user.email='.$this->d['author_email'],
				'commit', '-q', '-m', $message
			]);

			$this->git(['push', '-q', 'origin', 'HEAD:refs/heads/'.$this->d['branch']]);

			return strtok($message, "\n").($had_branch ? '' : ' (new branch)');
		}
		finally {
			$this->dropSecrets();
		}
	}

	private function dropSecrets(): void {
		foreach ($this->cleanup as $file) {
			@unlink($file);
		}

		$this->cleanup = [];
	}

	private function writeTree(Store $store, string $snapshotid): void {
		// Everything is regenerated from the snapshot: deleted objects disappear from the tree.
		foreach (scandir($this->workdir) as $entry) {
			if ($entry !== '.' && $entry !== '..' && $entry !== '.git' && $entry !== 'README.md') {
				$path = $this->workdir.'/'.$entry;
				is_dir($path) ? Store::rmTree($path) : unlink($path);
			}
		}

		$skip = $this->d['git_exclude_people'] ? ['user', 'usergroup', 'mediatype'] : [];
		$meta = $store->getMeta($snapshotid);

		foreach (Types::all() as $type => $def) {
			if (in_array($type, $skip, true) || empty($meta['counts'][$type])) {
				continue;
			}

			$index = $store->getIndex($snapshotid, $type);
			$objects = $store->getObjects($snapshotid, $type, array_column($index, 'id'));
			$dir = $this->workdir.'/'.$type;
			mkdir($dir, 0700, true);
			$used = [];

			foreach ($index as $entry) {
				$object = $objects[$entry['id']] ?? null;

				if ($object === null) {
					continue;
				}

				$base = self::fileName($entry['tech'] ?? $entry['name']);

				// Two objects with the same name (actions in different event sources): disambiguate by ID.
				if (isset($used[strtolower($base)])) {
					$base .= '~'.$entry['id'];
				}
				$used[strtolower($base)] = true;

				file_put_contents($dir.'/'.$base.'.json', Restorer::canonical($type, $object['data'])."\n");
			}
		}

		if (!is_file($this->workdir.'/README.md')) {
			file_put_contents($this->workdir.'/README.md', "# Zabbix configuration\n\n"
				."Written by the Config backup module. One file per object, IDs and runtime state removed, keys sorted.\n"
				."Do not edit by hand: every run regenerates the tree from the latest snapshot.\n"
			);
		}
	}

	public static function fileName(string $name): string {
		$name = preg_replace('~[/\\\\:*?"<>|\x00-\x1f]+~', '_', $name);
		$name = trim($name, ' .');

		return mb_substr($name !== '' ? $name : '_', 0, 150);
	}

	private static function commitMessage(string $status, array $meta): string {
		$labels = Types::labels();
		$verbs = ['A' => 'added', 'M' => 'changed', 'D' => 'deleted'];
		$counts = [];
		$lines = [];

		foreach (array_filter(explode("\n", trim($status))) as $line) {
			[$code, $path] = explode("\t", $line, 2) + [1 => ''];
			$code = $code[0];
			[$type, $file] = explode('/', $path, 2) + [1 => ''];

			if ($file === '') {
				continue;
			}

			$verb = $verbs[$code] ?? 'changed';
			$counts[$verb] = ($counts[$verb] ?? 0) + 1;
			$lines[] = sprintf('%s %s "%s"', ucfirst($verb), strtolower($labels[$type] ?? $type),
				preg_replace('/\.json$/', '', $file)
			);
		}

		$summary = implode(', ', array_map(static fn($v, $n) => $n.' '.$v, array_keys($counts), $counts));

		if (count($lines) == 1) {
			$subject = $lines[0];
		}
		else {
			$subject = sprintf('%s: %s', date('Y-m-d H:i', $meta['created']), $summary);
		}

		return $subject."\n\n".implode("\n", array_slice($lines, 0, 200))
			.(count($lines) > 200 ? sprintf("\n... and %d more", count($lines) - 200) : '')
			."\n\nSnapshot ".$meta['id'].($meta['label'] !== '' ? ' ('.$meta['label'].')' : '')
			.', Zabbix '.($meta['zabbix_version'] ?? '?')."\n";
	}
}
