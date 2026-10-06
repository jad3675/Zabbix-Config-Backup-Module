<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * Line diff (LCS) good enough for a few thousand lines of pretty-printed JSON. Larger inputs fall back to
 * trimming the common head and tail and showing the middle as a block replace.
 */
class Diff {

	public const SAME = ' ';
	public const ADD = '+';
	public const DEL = '-';

	private const MAX_CELLS = 4000000;

	/**
	 * @return array  [[op, line], ...]
	 */
	public static function lines(string $old, string $new): array {
		$a = $old === '' ? [] : explode("\n", $old);
		$b = $new === '' ? [] : explode("\n", $new);

		$head = [];
		while ($a && $b && $a[0] === $b[0]) {
			$head[] = [self::SAME, array_shift($a)];
			array_shift($b);
		}

		$tail = [];
		while ($a && $b && end($a) === end($b)) {
			array_unshift($tail, [self::SAME, array_pop($a)]);
			array_pop($b);
		}

		$n = count($a);
		$m = count($b);

		if ($n * $m > self::MAX_CELLS) {
			$middle = array_merge(
				array_map(static fn($l) => [self::DEL, $l], $a),
				array_map(static fn($l) => [self::ADD, $l], $b)
			);

			return array_merge($head, $middle, $tail);
		}

		// LCS lengths, row by row.
		$len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--) {
			for ($j = $m - 1; $j >= 0; $j--) {
				$len[$i][$j] = $a[$i] === $b[$j]
					? $len[$i + 1][$j + 1] + 1
					: max($len[$i + 1][$j], $len[$i][$j + 1]);
			}
		}

		$middle = [];
		$i = 0;
		$j = 0;
		while ($i < $n && $j < $m) {
			if ($a[$i] === $b[$j]) {
				$middle[] = [self::SAME, $a[$i]];
				$i++;
				$j++;
			}
			elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
				$middle[] = [self::DEL, $a[$i++]];
			}
			else {
				$middle[] = [self::ADD, $b[$j++]];
			}
		}
		while ($i < $n) {
			$middle[] = [self::DEL, $a[$i++]];
		}
		while ($j < $m) {
			$middle[] = [self::ADD, $b[$j++]];
		}

		return array_merge($head, $middle, $tail);
	}

	/**
	 * Collapses long unchanged runs to $context lines around each change.
	 *
	 * @return array  [[op, line] | ['…', skipped_count], ...]
	 */
	public static function hunks(array $lines, int $context = 4): array {
		$changed = [];
		foreach ($lines as $i => [$op]) {
			if ($op !== self::SAME) {
				$changed[] = $i;
			}
		}

		if (!$changed) {
			return [];
		}

		$keep = [];
		foreach ($changed as $i) {
			for ($k = max(0, $i - $context); $k <= min(count($lines) - 1, $i + $context); $k++) {
				$keep[$k] = true;
			}
		}

		$out = [];
		$skipped = 0;
		foreach ($lines as $i => $line) {
			if (isset($keep[$i])) {
				if ($skipped) {
					$out[] = ['…', $skipped];
					$skipped = 0;
				}
				$out[] = $line;
			}
			else {
				$skipped++;
			}
		}
		if ($skipped) {
			$out[] = ['…', $skipped];
		}

		return $out;
	}
}
