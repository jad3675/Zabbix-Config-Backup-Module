<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * When is a schedule due? Times are in the zone set in Settings (Settings::timezone()), passed in explicitly so
 * the runner, the frontend (which switches PHP to each user's own zone) and the CLI always agree.
 *
 *   daily  at HH:MM
 *   weekly on <weekday> at HH:MM
 *   hours  every N hours, counted from HH:MM each day (6 at 02:30 = 02:30, 08:30, 14:30, 20:30)
 *
 * A missed slot (server down, runner stopped) runs once when the runner is back, not once per missed slot.
 */
class Schedule {

	private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

	/**
	 * Most recent slot at or before $now.
	 */
	public static function lastSlot(array $s, int $now, string $tz = 'UTC'): int {
		return self::in($tz, static fn() => self::lastSlotLocal($s, $now));
	}

	public static function nextSlot(array $s, int $now, string $tz = 'UTC'): int {
		return self::in($tz, static fn() => self::nextSlotLocal($s, $now));
	}

	public static function format(int $time, string $tz = 'UTC', string $format = 'Y-m-d H:i'): string {
		return self::in($tz, static fn() => date($format, $time));
	}

	/**
	 * Run $fn with PHP's default zone set to $tz, then put it back.
	 */
	private static function in(string $tz, callable $fn) {
		$old = date_default_timezone_get();
		date_default_timezone_set($tz !== '' ? $tz : 'UTC');

		try {
			return $fn();
		}
		finally {
			date_default_timezone_set($old);
		}
	}

	private static function lastSlotLocal(array $s, int $now): int {
		[$h, $m] = array_map('intval', explode(':', $s['at']));

		switch ($s['every']) {
			case 'weekly':
				for ($back = 0; $back <= 7; $back++) {
					$day = strtotime(sprintf('-%d days', $back), $now);
					$slot = mktime($h, $m, 0, (int) date('n', $day), (int) date('j', $day), (int) date('Y', $day));

					if ((int) date('w', $slot) === (int) $s['weekday'] && $slot <= $now) {
						return $slot;
					}
				}
				break;

			case 'hours':
				$step = (int) $s['hours'];

				for ($back = 0; $back <= 1 + intdiv($step, 24); $back++) {
					$day = strtotime(sprintf('-%d days', $back), $now);
					$base = mktime($h, $m, 0, (int) date('n', $day), (int) date('j', $day), (int) date('Y', $day));
					$best = null;

					for ($slot = $base; $slot < $base + 86400; $slot += $step * 3600) {
						if ($slot <= $now) {
							$best = $slot;
						}
					}

					if ($best !== null) {
						return $best;
					}
				}
				break;
		}

		// daily, and fallback
		$today = mktime($h, $m, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));

		return $today <= $now ? $today : strtotime('-1 day', $today);
	}

	private static function nextSlotLocal(array $s, int $now): int {
		// Smallest slot strictly after $now: probe forward in 5 minute steps from the last slot.
		$last = self::lastSlotLocal($s, $now);

		for ($t = $now + 60; $t <= $now + 8 * 86400; $t += 300) {
			$slot = self::lastSlotLocal($s, $t);

			if ($slot > $last) {
				return $slot;
			}
		}

		return $last + 86400;
	}

	public static function describe(array $s): string {
		switch ($s['every']) {
			case 'hours':
				return sprintf('Every %dh from %s', $s['hours'], $s['at']);

			case 'weekly':
				return sprintf('%s at %s', self::DAYS[$s['weekday']], $s['at']);
		}

		return 'Daily at '.$s['at'];
	}

	public static function days(): array {
		return self::DAYS;
	}
}
