<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Minimal ustar writer/reader for flat snapshot directories. A shipped snapshot is a plain tar of its directory,
 * so after decrypting, `tar xf` is all anyone needs.
 */
class Tar {

	public static function create(string $dir, string $tar_file, string $prefix): void {
		$out = fopen($tar_file, 'wb');

		foreach (scandir($dir) as $name) {
			$path = $dir.'/'.$name;

			if ($name[0] === '.' || !is_file($path)) {
				continue;
			}

			$size = filesize($path);
			fwrite($out, self::header($prefix.'/'.$name, $size, filemtime($path)));

			$in = fopen($path, 'rb');
			stream_copy_to_stream($in, $out);
			fclose($in);

			if ($size % 512) {
				fwrite($out, str_repeat("\0", 512 - $size % 512));
			}
		}

		fwrite($out, str_repeat("\0", 1024));
		fclose($out);
	}

	private static function header(string $name, int $size, int $mtime): string {
		if (strlen($name) > 99) {
			throw new \RuntimeException('Name too long for tar: '.$name);
		}

		$h = str_pad($name, 100, "\0")
			.sprintf('%07o', 0640)."\0"
			.sprintf('%07o', 0)."\0"
			.sprintf('%07o', 0)."\0"
			.sprintf('%011o', $size)."\0"
			.sprintf('%011o', $mtime)."\0"
			.'        '
			.'0'
			.str_repeat("\0", 100)
			."ustar\0".'00'
			.str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

		$sum = 0;
		for ($i = 0; $i < 512; $i++) {
			$sum += ord($h[$i]);
		}

		return substr_replace($h, sprintf('%06o', $sum)."\0 ", 148, 8);
	}

	/**
	 * Extracts regular files into $dir, flattening paths. Refuses anything that isn't a plain snapshot file.
	 *
	 * @return string  the directory prefix found in the archive (the snapshot ID)
	 */
	public static function extract(string $tar_file, string $dir): string {
		$in = fopen($tar_file, 'rb');
		$prefix = '';

		while (($h = fread($in, 512)) !== false && strlen($h) == 512) {
			if (trim($h, "\0") === '') {
				break;
			}

			$name = rtrim(substr($h, 0, 100), "\0");
			$size = octdec(trim(substr($h, 124, 12), "\0 "));
			$type = $h[156];

			$parts = explode('/', $name);
			$base = end($parts);
			$prefix = count($parts) > 1 ? $parts[0] : $prefix;

			if (($type === '0' || $type === "\0") && preg_match('/^[a-z_]+(\.index\.json|\.jsonl\.gz)$|^(meta|remap)\.json$/', $base)) {
				$out = fopen($dir.'/'.$base, 'wb');
				$left = $size;

				while ($left > 0) {
					$chunk = fread($in, min(1048576, $left));
					fwrite($out, $chunk);
					$left -= strlen($chunk);
				}

				fclose($out);
			}
			else {
				fseek($in, $size, SEEK_CUR);
			}

			if ($size % 512) {
				fseek($in, 512 - $size % 512, SEEK_CUR);
			}
		}

		fclose($in);

		return $prefix;
	}
}
