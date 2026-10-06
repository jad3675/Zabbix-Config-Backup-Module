<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * RSA-OAEP + AES-256-GCM, openssl only.
 *
 * Two uses:
 *  - secrets in settings.json (destination passwords, API token) are sealed with the runner's own key pair,
 *    so they are never shown in the frontend and settings.json on its own gives nothing away;
 *  - snapshots leaving the server are encrypted with a public key you supply. Only the matching private key
 *    reads them: not the bucket admin, not whoever finds the SFTP share.
 *
 * File format (.cbk):
 *   "ZCB1" | u16 keylen | RSA-OAEP(sha1)(aes key) | 8-byte nonce prefix
 *   then chunks: u32 plain length | 16-byte GCM tag | ciphertext      (1 MiB plain per chunk)
 *   last chunk has length 0 and authenticates the end of the stream (no silent truncation).
 * Each chunk's nonce is prefix || u32 chunk number, AAD is "ZCB1" || chunk number || final flag.
 */
class Sealer {

	private const MAGIC = 'ZCB1';
	private const CHUNK = 1048576;

	public static function available(): bool {
		return extension_loaded('openssl');
	}

	/**
	 * @return array  [private PEM, public PEM]
	 */
	public static function generateKeyPair(int $bits = 3072): array {
		$key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

		if ($key === false) {
			throw new \RuntimeException('openssl_pkey_new failed: '.openssl_error_string());
		}

		openssl_pkey_export($key, $private);

		return [$private, openssl_pkey_get_details($key)['key']];
	}

	public static function fingerprint(string $public_pem): string {
		$der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $public_pem));

		return 'SHA256:'.rtrim(base64_encode(hash('sha256', $der, true)), '=');
	}

	/**
	 * Throws if the PEM is not an RSA public key we can encrypt to.
	 */
	public static function checkPublicKey(string $public_pem): void {
		$key = openssl_pkey_get_public($public_pem);

		if ($key === false) {
			throw new \InvalidArgumentException('Not a PEM public key.');
		}

		$details = openssl_pkey_get_details($key);

		if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
			throw new \InvalidArgumentException('Use an RSA public key of at least 2048 bits.');
		}
	}

	private static function wrapKey(string $aes_key, string $public_pem): string {
		if (!openssl_public_encrypt($aes_key, $wrapped, $public_pem, OPENSSL_PKCS1_OAEP_PADDING)) {
			throw new \RuntimeException('Cannot encrypt with the public key: '.openssl_error_string());
		}

		return $wrapped;
	}

	private static function unwrapKey(string $wrapped, string $private_pem): string {
		if (!openssl_private_decrypt($wrapped, $aes_key, $private_pem, OPENSSL_PKCS1_OAEP_PADDING)) {
			throw new \RuntimeException('Cannot decrypt: wrong private key, or the data is damaged.');
		}

		return $aes_key;
	}

	/*
	 * Small strings (settings secrets): "sealed:" base64(...).
	 */

	public static function seal(string $plain, string $public_pem): string {
		$key = random_bytes(32);
		$iv = random_bytes(12);
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		$wrapped = self::wrapKey($key, $public_pem);

		return 'sealed:'.base64_encode(pack('n', strlen($wrapped)).$wrapped.$iv.$tag.$cipher);
	}

	public static function isSealed($value): bool {
		return is_string($value) && strncmp($value, 'sealed:', 7) == 0;
	}

	public static function open(string $sealed, string $private_pem): string {
		$raw = base64_decode(substr($sealed, 7), true);

		if ($raw === false || strlen($raw) < 2) {
			throw new \RuntimeException('Damaged sealed value.');
		}

		$len = unpack('n', $raw)[1];
		$wrapped = substr($raw, 2, $len);
		$iv = substr($raw, 2 + $len, 12);
		$tag = substr($raw, 14 + $len, 16);
		$cipher = substr($raw, 30 + $len);
		$plain = openssl_decrypt($cipher, 'aes-256-gcm', self::unwrapKey($wrapped, $private_pem), OPENSSL_RAW_DATA,
			$iv, $tag
		);

		if ($plain === false) {
			throw new \RuntimeException('Sealed value failed authentication.');
		}

		return $plain;
	}

	/*
	 * Files (snapshots), streamed in chunks.
	 */

	public static function encryptFile(string $in, string $out, string $public_pem): void {
		$key = random_bytes(32);
		$prefix = random_bytes(8);
		$wrapped = self::wrapKey($key, $public_pem);

		$src = fopen($in, 'rb');
		$dst = fopen($out, 'wb');
		fwrite($dst, self::MAGIC.pack('n', strlen($wrapped)).$wrapped.$prefix);

		$n = 0;
		do {
			$plain = (string) fread($src, self::CHUNK);
			$final = $plain === '' || feof($src);

			// Peek: if we read a full chunk exactly at EOF, the zero-length final chunk follows.
			if ($plain !== '' && $final) {
				self::writeChunk($dst, $key, $prefix, $n++, $plain, false);
				$plain = '';
			}

			self::writeChunk($dst, $key, $prefix, $n++, $plain, $plain === '');
		}
		while ($plain !== '');

		fclose($src);
		fclose($dst);
	}

	private static function writeChunk($dst, string $key, string $prefix, int $n, string $plain, bool $final): void {
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix.pack('N', $n), $tag,
			self::MAGIC.pack('N', $n).($final ? "\1" : "\0")
		);
		fwrite($dst, pack('N', strlen($plain)).$tag.$cipher);
	}

	public static function isEncryptedFile(string $file): bool {
		$fh = fopen($file, 'rb');
		$magic = fread($fh, 4);
		fclose($fh);

		return $magic === self::MAGIC;
	}

	public static function decryptFile(string $in, string $out, string $private_pem): void {
		$src = fopen($in, 'rb');

		if (fread($src, 4) !== self::MAGIC) {
			throw new \RuntimeException('Not an encrypted Config backup file.');
		}

		$len = unpack('n', fread($src, 2))[1];
		$key = self::unwrapKey(fread($src, $len), $private_pem);
		$prefix = fread($src, 8);
		$dst = fopen($out, 'wb');

		for ($n = 0; ; $n++) {
			$head = fread($src, 20);

			if (strlen($head) < 20) {
				fclose($dst);
				@unlink($out);

				throw new \RuntimeException('Encrypted file is truncated.');
			}

			$size = unpack('N', substr($head, 0, 4))[1];
			$tag = substr($head, 4, 16);
			$cipher = $size > 0 ? fread($src, $size) : '';
			$final = $size == 0;
			$plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix.pack('N', $n), $tag,
				self::MAGIC.pack('N', $n).($final ? "\1" : "\0")
			);

			if ($plain === false) {
				fclose($dst);
				@unlink($out);

				throw new \RuntimeException(sprintf('Encrypted file failed authentication at chunk %d.', $n));
			}

			if ($final) {
				break;
			}

			fwrite($dst, $plain);
		}

		fclose($src);
		fclose($dst);
	}
}
