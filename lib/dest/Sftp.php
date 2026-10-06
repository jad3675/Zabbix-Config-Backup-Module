<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib\Dest;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP as PhpseclibSftp;

/**
 * SFTP with phpseclib (bundled, pure PHP: no ssh2 extension needed).
 *
 * Host key: if a fingerprint is configured, anything else is refused. If not, the first key seen is recorded
 * (trust on first use, like ssh's accept-new) and every later connection must match it.
 */
class Sftp implements Remote {

	private array $d;
	private string $password;
	private string $key;
	private string $passphrase;
	private ?string $trusted;
	private ?PhpseclibSftp $sftp = null;

	public ?string $seen_host_key = null;

	public function __construct(array $d, string $password, string $key, string $passphrase, ?string $trusted) {
		require_once __DIR__.'/../../vendor/autoload.php';

		if ($d['host'] === '' || $d['username'] === '' || ($password === '' && $key === '')) {
			throw new \InvalidArgumentException('SFTP destination needs a host, user name and a password or key.');
		}

		$this->d = $d;
		$this->password = $password;
		$this->key = $key;
		$this->passphrase = $passphrase;
		$this->trusted = $d['host_key'] !== '' ? $d['host_key'] : $trusted;
	}

	public static function fingerprint(string $host_key): string {
		$parts = explode(' ', trim($host_key));
		$blob = base64_decode($parts[1] ?? '', true);

		return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) $blob, true)), '=');
	}

	private function conn(): PhpseclibSftp {
		if ($this->sftp !== null) {
			return $this->sftp;
		}

		$sftp = new PhpseclibSftp($this->d['host'], (int) $this->d['port'], 20);
		$host_key = $sftp->getServerPublicHostKey();

		if ($host_key === false) {
			throw new \RuntimeException(sprintf('SFTP: cannot connect to %s:%d.', $this->d['host'], $this->d['port']));
		}

		$this->seen_host_key = self::fingerprint($host_key);

		if ($this->trusted !== null && $this->trusted !== '' && !hash_equals($this->trusted, $this->seen_host_key)) {
			throw new \RuntimeException(sprintf(
				'SFTP: host key for %s is %s, expected %s. Refusing to connect (possible man in the middle, or the server was rebuilt).',
				$this->d['host'], $this->seen_host_key, $this->trusted
			));
		}

		$credential = $this->key !== ''
			? PublicKeyLoader::load($this->key, $this->passphrase !== '' ? $this->passphrase : false)
			: $this->password;

		if (!$sftp->login($this->d['username'], $credential)) {
			throw new \RuntimeException(sprintf('SFTP: login as %s on %s failed.', $this->d['username'], $this->d['host']));
		}

		$path = $this->d['path'];

		if ($path !== '' && !$sftp->is_dir($path) && !$sftp->mkdir($path, 0750, true)) {
			throw new \RuntimeException(sprintf('SFTP: cannot create directory %s.', $path));
		}

		return $this->sftp = $sftp;
	}

	private function remote(string $name): string {
		return ($this->d['path'] !== '' ? $this->d['path'].'/' : '').$name;
	}

	public function put(string $local_file, string $name): void {
		$sftp = $this->conn();
		$tmp = $this->remote('.'.$name.'.part');

		if (!$sftp->put($tmp, $local_file, PhpseclibSftp::SOURCE_LOCAL_FILE)) {
			throw new \RuntimeException('SFTP: upload of '.$name.' failed: '.implode('; ', $sftp->getSFTPErrors()));
		}

		$sftp->delete($this->remote($name));

		if (!$sftp->rename($tmp, $this->remote($name))) {
			throw new \RuntimeException('SFTP: cannot rename the uploaded '.$name.' into place.');
		}
	}

	public function list(): array {
		$out = [];

		foreach ($this->conn()->rawlist($this->d['path'] !== '' ? $this->d['path'] : '.') ?: [] as $name => $attr) {
			if ($name[0] !== '.' && ($attr['type'] ?? 0) == 1) {
				$out[$name] = ['size' => (int) ($attr['size'] ?? 0), 'time' => (int) ($attr['mtime'] ?? 0)];
			}
		}

		return $out;
	}

	public function get(string $name, string $local_file): void {
		if (!$this->conn()->get($this->remote($name), $local_file.'.part')) {
			@unlink($local_file.'.part');

			throw new \RuntimeException('SFTP: download of '.$name.' failed.');
		}

		rename($local_file.'.part', $local_file);
	}

	public function delete(string $name): void {
		$this->conn()->delete($this->remote($name));
	}

	public function test(): string {
		$probe = '.configbackup-test-'.bin2hex(random_bytes(4));
		$tmp = tempnam(sys_get_temp_dir(), 'cbt');
		file_put_contents($tmp, 'test');

		try {
			$this->put($tmp, $probe);
			$count = count($this->list());
			$this->delete($probe);
		}
		finally {
			@unlink($tmp);
		}

		return sprintf('Write, list and delete OK in %s@%s:%s (%d files). Host key %s.', $this->d['username'],
			$this->d['host'], $this->d['path'] ?: '~', $count, $this->seen_host_key
		);
	}
}
