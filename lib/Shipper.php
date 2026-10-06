<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

use Modules\ConfigBackup\Lib\Dest\Git;
use Modules\ConfigBackup\Lib\Dest\Remote;
use Modules\ConfigBackup\Lib\Dest\S3;
use Modules\ConfigBackup\Lib\Dest\Sftp;

/**
 * Copies snapshots to destinations and back. Runner side only: it is the only thing that can open the sealed
 * credentials.
 *
 * On S3/SFTP a snapshot is one file: <snapshotid>.<tag>.tar, or .tar.cbk when a public key is set (see Sealer).
 * <tag> is the schedule ID, or "manual". Remote retention counts per tag, so an hourly schedule of four types
 * never pushes the nightly full snapshots out, and only ever touches files named like this.
 */
class Shipper {

	public const NAME = '/^(\d{8}-\d{6}-[a-f0-9]{6})(?:\.([a-z0-9]{1,32}))?\.tar(\.cbk)?$/';

	private Store $store;
	private string $storage;

	public function __construct(Store $store) {
		$this->store = $store;
		$this->storage = $store->getRoot();
	}

	private function secret(array $d, string $field): string {
		return Runtime::reveal($this->storage, $d[$field]);
	}

	private function tmp(string $suffix): string {
		$dir = Runtime::stateDir($this->storage).'/tmp';

		if (!is_dir($dir)) {
			mkdir($dir, 0700);
		}

		return $dir.'/'.bin2hex(random_bytes(6)).$suffix;
	}

	public function remote(array $d): Remote {
		switch ($d['kind']) {
			case 's3':
				return new S3($d, $this->secret($d, 'secret_key'));

			case 'sftp':
				$state = Runtime::read($this->storage, 'destinations')[$d['id']] ?? [];

				return new Sftp($d, $this->secret($d, 'password'), $this->secret($d, 'ssh_key'),
					$this->secret($d, 'ssh_key_passphrase'), $state['host_key_seen'] ?? null
				);
		}

		throw new \InvalidArgumentException('Destination kind '.$d['kind'].' has no file storage.');
	}

	private function git(array $d): Git {
		if (!Git::available()) {
			throw new \RuntimeException('The git command is not installed on this server.');
		}

		return new Git($d, Runtime::stateDir($this->storage).'/git/'.$d['id'],
			$this->secret($d, $d['git_auth'] === 'ssh' ? 'ssh_key' : 'git_token')
		);
	}

	private function remember(array $d, array $changes, ?Remote $remote = null): void {
		if ($remote instanceof Sftp && $remote->seen_host_key !== null) {
			$changes['host_key_seen'] = $remote->seen_host_key;
		}

		Runtime::update($this->storage, 'destinations', $d['id'], $changes);
	}

	/**
	 * What we know about each archive on a destination beyond its file name (label, objects...), recorded
	 * when this server sent it. Lets the snapshot list describe remote-only snapshots properly.
	 */
	private function catalog(array $d): array {
		return Runtime::read($this->storage, 'destinations')[$d['id']]['catalog'] ?? [];
	}

	public function test(array $d): string {
		$remote = null;

		try {
			if ($d['kind'] === 'git') {
				$message = $this->git($d)->test();
			}
			else {
				$remote = $this->remote($d);
				$message = $remote->test();
			}

			if ($d['public_key'] !== '') {
				Sealer::checkPublicKey($d['public_key']);
				$message .= ' Snapshots are encrypted to '.Sealer::fingerprint($d['public_key']).'.';
			}

			$this->remember($d, ['test_time' => time(), 'test_ok' => true, 'test_message' => $message], $remote);

			return $message;
		}
		catch (\Throwable $e) {
			$this->remember($d, ['test_time' => time(), 'test_ok' => false, 'test_message' => $e->getMessage()],
				$remote
			);

			throw $e;
		}
	}

	public function ship(string $snapshotid, array $d): string {
		$remote = null;

		try {
			if ($d['kind'] === 'git') {
				$message = $this->git($d)->publish($this->store, $snapshotid);
				$this->store->addUpload($snapshotid, $d['id'], ['time' => time(), 'name' => 'git']);
				$this->remember($d, ['last_time' => time(), 'last_ok' => true, 'last_message' => $message,
					'last_snapshot' => $snapshotid
				]);

				return $message;
			}

			$remote = $this->remote($d);
			$tar = $this->tmp('.tar');
			$upload = $tar;
			$tag = $this->store->getMeta($snapshotid)['schedule'] ?? null;
			$name = $snapshotid.'.'.(is_string($tag) && preg_match('/^[a-z0-9]{1,32}$/', $tag) ? $tag : 'manual').'.tar';

			try {
				Tar::create($this->store->getRoot().'/'.$snapshotid, $tar, $snapshotid);

				if ($d['public_key'] !== '') {
					$upload = $tar.'.cbk';
					$name .= '.cbk';
					Sealer::encryptFile($tar, $upload, $d['public_key']);
				}

				$size = filesize($upload);
				$remote->put($upload, $name);
			}
			finally {
				@unlink($tar);
				@unlink($tar.'.cbk');
			}

			$this->store->addUpload($snapshotid, $d['id'], ['time' => time(), 'name' => $name, 'size' => $size]);
			$pruned = $this->pruneRemote($remote, $d);
			$listing = $remote->list();

			$message = sprintf('Uploaded %s (%s)%s.', $name, self::bytes($size),
				$pruned ? sprintf(', retention removed %d old', count($pruned)) : ''
			);

			$meta = $this->store->getMeta($snapshotid);
			$catalog = $this->catalog($d);
			$catalog[$name] = [
				'label' => $meta['label'], 'created' => $meta['created'], 'source' => $meta['source'],
				'schedule' => $meta['schedule'] ?? null, 'objects' => array_sum($meta['counts']),
				'status' => $meta['status'], 'zabbix_version' => $meta['zabbix_version'] ?? null
			];
			$listing = self::ours($listing);

			$this->remember($d, ['last_time' => time(), 'last_ok' => true, 'last_message' => $message,
				'last_snapshot' => $snapshotid, 'listing' => $listing, 'listing_time' => time(),
				'catalog' => array_intersect_key($catalog, $listing)
			], $remote);

			return $message;
		}
		catch (\Throwable $e) {
			$this->remember($d, ['last_time' => time(), 'last_ok' => false, 'last_message' => $e->getMessage()],
				$remote
			);

			throw $e;
		}
	}

	public function listRemote(array $d): array {
		$remote = $this->remote($d);
		$listing = self::ours($remote->list());
		$this->remember($d, ['listing' => $listing, 'listing_time' => time(),
			'catalog' => array_intersect_key($this->catalog($d), $listing)
		], $remote);

		return $listing;
	}

	/**
	 * Only our files, newest first.
	 */
	private static function ours(array $listing): array {
		$listing = array_filter($listing, static fn($name) => preg_match(self::NAME, (string) $name),
			ARRAY_FILTER_USE_KEY
		);
		krsort($listing, SORT_STRING);

		return $listing;
	}

	private function pruneRemote(Remote $remote, array $d): array {
		$cutoff = $d['keep_days'] > 0 ? time() - $d['keep_days'] * 86400 : null;
		$deleted = [];
		$position = [];

		foreach (self::ours($remote->list()) as $name => $info) {
			preg_match(self::NAME, $name, $m);
			$tag = ($m[2] ?? '') !== '' ? $m[2] : 'manual';
			$position[$tag] = ($position[$tag] ?? 0) + 1;

			if ($position[$tag] == 1) {
				continue;
			}

			$created = strtotime(substr($m[1], 0, 8).'T'.substr($m[1], 9, 6).'Z');

			if ($position[$tag] > $d['keep_count'] || ($cutoff !== null && $created < $cutoff)) {
				$remote->delete($name);
				$deleted[] = $name;
			}
		}

		return $deleted;
	}

	/**
	 * Fetch a snapshot back into local storage.
	 *
	 * @param string|null $private_pem  for encrypted archives; defaults to the destination's private_key_path
	 */
	public function pull(array $d, string $name, ?string $private_pem = null): string {
		if (!preg_match(self::NAME, $name, $m)) {
			throw new \InvalidArgumentException('Not a Config backup archive name: '.$name);
		}

		$snapshotid = $m[1];

		if ($this->store->exists($snapshotid)) {
			throw new \RuntimeException(sprintf('Snapshot %s is already in local storage.', $snapshotid));
		}

		$file = $this->tmp('.pull');
		$tar = $file.'.tar';
		$dir = $this->store->getRoot().'/.tmp-pull-'.bin2hex(random_bytes(3));

		try {
			$this->remote($d)->get($name, $file);

			if (Sealer::isEncryptedFile($file)) {
				if ($private_pem === null) {
					if ($d['private_key_path'] === '' || !is_readable($d['private_key_path'])) {
						throw new \RuntimeException(
							'This snapshot is encrypted. Set a readable private key path on the destination, or pull it with the command line: pull <dest> <name> --key=<file>.'
						);
					}

					$private_pem = (string) file_get_contents($d['private_key_path']);
				}

				Sealer::decryptFile($file, $tar, $private_pem);
			}
			else {
				rename($file, $tar);
			}

			mkdir($dir, 0750);
			$found = Tar::extract($tar, $dir);

			if ($found !== $snapshotid) {
				throw new \RuntimeException(sprintf('Archive %s holds snapshot "%s", not %s.', $name, $found, $snapshotid));
			}

			// Pinned on arrival: an old snapshot pulled back would otherwise be first in line for retention.
			$this->store->adopt($dir, $snapshotid, [
				'pulled_from' => ['destination' => $d['id'], 'time' => time(), 'name' => $name],
				'pinned' => true,
				'uploads' => []
			]);
		}
		finally {
			@unlink($file);
			@unlink($tar);

			if (is_dir($dir)) {
				Store::rmTree($dir);
			}
		}

		return sprintf('Pulled %s from %s and pinned it.', $snapshotid, $d['name']);
	}

	public static function idFromName(string $name): ?string {
		return preg_match(self::NAME, $name, $m) ? $m[1] : null;
	}

	public static function bytes(int $size): string {
		foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
			if ($size < 1024 || $unit === 'GB') {
				return ($unit === 'B' ? $size : round($size, 1)).' '.$unit;
			}

			$size /= 1024;
		}

		return '';
	}
}
