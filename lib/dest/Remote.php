<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib\Dest;

/**
 * A place snapshot archives can be copied to and back from. Names are flat (<snapshotid>.tar or .tar.cbk);
 * implementations put them under their own prefix/path.
 */
interface Remote {

	public function put(string $local_file, string $name): void;

	/**
	 * @return array  name => ['size' => int, 'time' => int]
	 */
	public function list(): array;

	public function get(string $name, string $local_file): void;

	public function delete(string $name): void;

	/**
	 * Cheap connectivity/permissions check. Returns a one-line description of what was verified.
	 */
	public function test(): string;
}
