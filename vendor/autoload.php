<?php
/*
 * Bundled third-party code, loaded only by the SFTP destination:
 *   phpseclib 3.0.47 (MIT)                       vendor/phpseclib
 *   paragonie/constant_time_encoding 3.0.0 (MIT) vendor/paragonie
 */
spl_autoload_register(static function (string $class): void {
	static $map = [
		'phpseclib3\\' => __DIR__.'/phpseclib/src/',
		'ParagonIE\\ConstantTime\\' => __DIR__.'/paragonie/src/'
	];

	foreach ($map as $prefix => $dir) {
		if (strncmp($class, $prefix, strlen($prefix)) == 0) {
			$file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

			if (is_file($file)) {
				require $file;
			}

			return;
		}
	}
});
