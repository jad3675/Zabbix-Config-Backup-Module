<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Snapshot storage on disk.
 *
 * <root>/
 *   .lock                      flock() target, one backup at a time
 *   last_run.json              outcome of the most recent run, any source (for staleness warnings)
 *   <snapshotid>/
 *     meta.json                created, source, user, label, note, pinned, counts, errors, zabbix version
 *     <type>.index.json        [{id, name, tech}] for fast listing
 *     <type>.jsonl.gz          one JSON object per line: {"id":"..","name":"..","data":{..}}
 *     remap.json               old id -> new id for objects recreated from this snapshot
 *
 * Snapshots are written to a .tmp-* directory and renamed into place, so a half-written snapshot is never listed.
 */
class Store {

	private string $root;

	public function __construct(string $root) {
		$this->root = rtrim($root, '/');
	}

	public function getRoot(): string {
		return $this->root;
	}

	/**
	 * Returns null if the storage is usable, otherwise a human readable reason.
	 */
	public function check(): ?string {
		if ($this->root === '') {
			return 'Storage path is not set.';
		}

		$fix = ' Fix: run "sh contrib/install-runner.sh" from the module directory as root.';

		if (!is_dir($this->root) && !@mkdir($this->root, 0750, true)) {
			// Distinguish "missing" from "a parent directory does not let us through".
			$blocked = null;
			for ($dir = dirname($this->root); $dir !== '/' && $dir !== '.'; $dir = dirname($dir)) {
				if (file_exists($dir) || @is_dir($dir)) {
					if (!is_executable($dir) || !is_writable($dir)) {
						$blocked = $dir;
					}
					break;
				}
			}

			return sprintf('Storage path "%s" does not exist and user "%s" cannot create it%s.%s', $this->root,
				self::processUser(), $blocked !== null ? sprintf(' (no access to %s, %s)', $blocked, self::describe($blocked)) : '',
				$fix
			);
		}

		if (!is_writable($this->root)) {
			return sprintf('Storage path "%s" is not writable by user "%s" (it is %s).%s', $this->root,
				self::processUser(), self::describe($this->root), $fix
			);
		}

		$state = $this->root.'/.state';
		if (is_dir($state) && (!is_writable($state) || (is_file($state.'/settings.json') && !is_writable($state.'/settings.json')))) {
			return sprintf('"%s" is not writable by user "%s" (it is %s), probably because the command line was run as root.%s',
				$state, self::processUser(), self::describe($state), $fix
			);
		}

		return null;
	}

	/**
	 * "owned by root:root, mode 0755" for error messages.
	 */
	public static function describe(string $path): string {
		$owner = @fileowner($path);
		$group = @filegroup($path);
		$perms = @fileperms($path);

		if ($owner === false) {
			return 'not accessible';
		}

		$name = static fn($id, $fn, $key) => function_exists($fn) && ($e = @$fn($id)) ? $e['name'] : (string) $id;

		return sprintf('owned by %s:%s, mode %04o', $name($owner, 'posix_getpwuid', 'name'),
			$name($group, 'posix_getgrgid', 'name'), $perms & 07777
		);
	}

	public static function processUser(): string {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$pw = posix_getpwuid(posix_geteuid());

			if (is_array($pw)) {
				return $pw['name'];
			}
		}

		return get_current_user();
	}

	public static function isValidId(string $id): bool {
		return (bool) preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id);
	}

	private function dir(string $snapshotid): string {
		if (!self::isValidId($snapshotid)) {
			throw new \InvalidArgumentException('Invalid snapshot ID.');
		}

		return $this->root.'/'.$snapshotid;
	}

	public function exists(string $snapshotid): bool {
		return self::isValidId($snapshotid) && is_file($this->dir($snapshotid).'/meta.json');
	}

	/**
	 * @return resource|null  lock handle, or null if another backup holds it
	 */
	public function lock() {
		$fh = fopen($this->root.'/.lock', 'c');

		if ($fh === false) {
			throw new \RuntimeException('Cannot open lock file in '.$this->root);
		}

		if (!flock($fh, LOCK_EX | LOCK_NB)) {
			fclose($fh);

			return null;
		}

		return $fh;
	}

	public function unlock($fh): void {
		if (is_resource($fh)) {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	public function newWriter(): SnapshotWriter {
		$id = gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
		$tmp = $this->root.'/.tmp-'.$id;

		if (!mkdir($tmp, 0750)) {
			throw new \RuntimeException('Cannot create '.$tmp);
		}

		return new SnapshotWriter($id, $tmp, $this->root.'/'.$id);
	}

	/**
	 * All snapshots, newest first.
	 */
	public function listSnapshots(): array {
		$snapshots = [];

		foreach (glob($this->root.'/*/meta.json') ?: [] as $file) {
			$id = basename(dirname($file));

			if (!self::isValidId($id)) {
				continue;
			}

			$meta = json_decode((string) file_get_contents($file), true);

			if (is_array($meta)) {
				$snapshots[$id] = $meta + ['id' => $id];
			}
		}

		uasort($snapshots, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

		return $snapshots;
	}

	public function getMeta(string $snapshotid): array {
		$file = $this->dir($snapshotid).'/meta.json';

		if (!is_file($file)) {
			throw new \RuntimeException('Snapshot not found.');
		}

		return json_decode(file_get_contents($file), true) + ['id' => $snapshotid];
	}

	public function updateMeta(string $snapshotid, array $changes): array {
		$meta = $this->getMeta($snapshotid);

		foreach (['label', 'note', 'pinned'] as $key) {
			if (array_key_exists($key, $changes)) {
				$meta[$key] = $changes[$key];
			}
		}

		self::writeJson($this->dir($snapshotid).'/meta.json', $meta);

		return $meta;
	}

	public function delete(string $snapshotid): void {
		$dir = $this->dir($snapshotid);

		if (!is_dir($dir)) {
			return;
		}

		// Rename first so a partially deleted snapshot never shows up in the list.
		$trash = $this->root.'/.del-'.$snapshotid;
		rename($dir, $trash);
		self::rmTree($trash);
	}

	/**
	 * [{id, name, tech}] for a type in a snapshot.
	 */
	public function getIndex(string $snapshotid, string $type): array {
		$file = $this->dir($snapshotid).'/'.$type.'.index.json';

		if (!is_file($file)) {
			return [];
		}

		return json_decode(file_get_contents($file), true) ?: [];
	}

	/**
	 * Reads one object (or several) from a snapshot without decoding the whole type file.
	 *
	 * @return array  id => ['id', 'name', 'data']
	 */
	public function getObjects(string $snapshotid, string $type, array $ids): array {
		$file = $this->dir($snapshotid).'/'.$type.'.jsonl.gz';
		$found = [];

		if (!is_file($file) || !$ids) {
			return $found;
		}

		$prefixes = [];
		foreach ($ids as $id) {
			$prefixes['{"id":'.json_encode((string) $id).','] = (string) $id;
		}

		$gz = gzopen($file, 'rb');

		while (($line = gzgets($gz)) !== false) {
			$head = substr($line, 0, 64);

			foreach ($prefixes as $prefix => $id) {
				if (strncmp($head, $prefix, strlen($prefix)) == 0) {
					$found[$id] = json_decode($line, true);
					unset($prefixes[$prefix]);
					break;
				}
			}

			if (!$prefixes) {
				break;
			}
		}

		gzclose($gz);

		return $found;
	}

	public function getObject(string $snapshotid, string $type, string $id): ?array {
		return $this->getObjects($snapshotid, $type, [$id])[$id] ?? null;
	}

	public function getRemap(string $snapshotid): array {
		$file = $this->dir($snapshotid).'/remap.json';

		return is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
	}

	public function saveRemap(string $snapshotid, array $remap): void {
		self::writeJson($this->dir($snapshotid).'/remap.json', $remap);
	}

	public function size(string $snapshotid): int {
		$size = 0;

		foreach (glob($this->dir($snapshotid).'/*') ?: [] as $file) {
			$size += filesize($file);
		}

		return $size;
	}

	public function writeLastRun(array $run): void {
		self::writeJson($this->root.'/last_run.json', $run);

		if (($run['source'] ?? '') === 'cron') {
			self::writeJson($this->root.'/last_cron.json', $run);
		}
	}

	public function getLastRun(bool $cron_only = false): ?array {
		$file = $this->root.($cron_only ? '/last_cron.json' : '/last_run.json');

		return is_file($file) ? json_decode(file_get_contents($file), true) : null;
	}

	/**
	 * Retention: keep the newest $keep_count unpinned snapshots, and drop unpinned ones older than $keep_days
	 * (0 = no age limit). $filter limits which snapshots this rule looks at (one schedule's, or the manual ones);
	 * the newest snapshot of that group and the newest overall are never removed.
	 *
	 * @return array  deleted snapshot IDs
	 */
	public function prune(int $keep_count, int $keep_days, ?callable $filter = null): array {
		$deleted = [];
		$position = 0;
		$cutoff = $keep_days > 0 ? time() - $keep_days * 86400 : null;
		$all = $this->listSnapshots();
		$newest = array_key_first($all);
		$first = true;

		foreach ($all as $id => $meta) {
			if ($filter !== null && !$filter($meta)) {
				continue;
			}

			if ($first || $id === $newest) {
				$first = false;
				$position++;
				continue;
			}

			if (!empty($meta['pinned'])) {
				continue;
			}

			$position++;

			if (($keep_count > 0 && $position > $keep_count) || ($cutoff !== null && $meta['created'] < $cutoff)) {
				$this->delete($id);
				$deleted[] = $id;
			}
		}

		// Clean up after crashed runs.
		foreach (array_merge(glob($this->root.'/.tmp-*') ?: [], glob($this->root.'/.del-*') ?: []) as $dir) {
			if (is_dir($dir) && filemtime($dir) < time() - 86400) {
				self::rmTree($dir);
			}
		}

		return $deleted;
	}

	/**
	 * Remember that a snapshot was copied to a destination (shown on the snapshot page).
	 */
	public function addUpload(string $snapshotid, string $destid, array $info): void {
		$file = $this->dir($snapshotid).'/meta.json';
		$meta = json_decode((string) file_get_contents($file), true);
		$meta['uploads'][$destid] = $info;
		self::writeJson($file, $meta);
	}

	/**
	 * Move a directory holding an extracted snapshot into the store.
	 */
	public function adopt(string $dir, string $snapshotid, array $meta_changes): void {
		if (!self::isValidId($snapshotid) || !is_file($dir.'/meta.json')) {
			throw new \RuntimeException('Archive does not contain a Config backup snapshot.');
		}

		if (is_dir($this->root.'/'.$snapshotid)) {
			throw new \RuntimeException(sprintf('Snapshot %s is already here.', $snapshotid));
		}

		$meta = json_decode((string) file_get_contents($dir.'/meta.json'), true);
		self::writeJson($dir.'/meta.json', $meta_changes + $meta);
		@unlink($dir.'/remap.json');

		if (!rename($dir, $this->root.'/'.$snapshotid)) {
			throw new \RuntimeException('Cannot move the pulled snapshot into place.');
		}
	}

	public static function writeJson(string $file, $data): void {
		$tmp = $file.'.tmp'.getmypid();
		file_put_contents($tmp, json_encode($data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		));
		rename($tmp, $file);
	}

	public static function rmTree(string $dir): void {
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $dir.'/'.$entry;
			is_dir($path) && !is_link($path) ? self::rmTree($path) : unlink($path);
		}

		rmdir($dir);
	}
}
