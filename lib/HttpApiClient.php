<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

/**
 * JSON-RPC over HTTP with an API token. Used by the CLI / cron runner.
 */
class HttpApiClient implements ApiClient {

	private string $url;
	private string $token;
	private bool $verify_tls;
	private string $ca_file;
	private ?string $proxy;
	private int $timeout;
	private int $seq = 0;

	public function __construct(string $url, string $token, bool $verify_tls = true, ?string $proxy = null,
			int $timeout = 300, string $ca_file = '') {
		$this->ca_file = $ca_file;
		// Accept whatever got pasted from the browser: .../zabbix, .../zabbix/, .../zabbix.php?action=..., .../index.php
		$url = trim($url);
		$url = preg_replace('~[?#].*$~', '', $url);
		$url = preg_replace('~/(zabbix|index|api_jsonrpc)\.php$~', '', $url);
		$url = rtrim($url, '/').'/api_jsonrpc.php';

		$this->url = $url;
		$this->token = $token;
		$this->verify_tls = $verify_tls;
		$this->proxy = ($proxy === null || $proxy === '') ? null : $proxy;
		$this->timeout = $timeout;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function call(string $method, array $params) {
		$body = json_encode([
			'jsonrpc' => '2.0',
			'method' => $method,
			'params' => $params === [] ? new \stdClass() : $params,
			'id' => ++$this->seq
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

		$ch = curl_init($this->url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			// apiinfo.version refuses an Authorization header.
			CURLOPT_HTTPHEADER => $method === 'apiinfo.version'
				? ['Content-Type: application/json-rpc']
				: ['Content-Type: application/json-rpc', 'Authorization: Bearer '.$this->token],
			CURLOPT_SSL_VERIFYPEER => $this->verify_tls,
			CURLOPT_SSL_VERIFYHOST => $this->verify_tls ? 2 : 0
		]);

		if ($this->verify_tls && $this->ca_file !== '') {
			curl_setopt($ch, CURLOPT_CAINFO, $this->ca_file);
		}

		if ($this->proxy !== null) {
			// socks5h://host:port, http://host:port etc. curl figures out the type from the scheme.
			curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
		}

		$raw = curl_exec($ch);

		if ($raw === false) {
			$error = curl_error($ch);
			curl_close($ch);

			$tls = preg_match('/SSL|certificate|TLS/i', $error);

			throw new ApiException(sprintf('%s: cannot reach %s: %s. %s', $method, $this->url, $error, $tls
				? 'The certificate is not trusted from this server (self-signed or an internal CA). In Settings, give the CA certificate file, or untick "Verify TLS certificate".'
				: 'The runner calls this URL from the Zabbix frontend server itself; http://127.0.0.1/zabbix usually works there, and avoids firewalls and proxies.'
			));
		}

		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
		curl_close($ch);

		// Redirects are not followed: a POST would turn into a GET, and the token must not travel to
		// wherever the redirect points. Say where it goes instead.
		if ($code >= 300 && $code < 400) {
			throw new ApiException(sprintf('%s redirects (HTTP %d) to %s. Put the address it redirects to in Settings > Zabbix URL%s.',
				$this->url, $code, $location !== '' ? $location : 'somewhere else',
				strncmp($location, 'https://', 8) == 0 && strncmp($this->url, 'http://', 7) == 0 ? ' (it wants https)' : ''
			));
		}

		$response = json_decode($raw, true);

		if (!is_array($response)) {
			$hint = $code == 404 || stripos((string) $raw, '<html') !== false
				? sprintf(' There is no Zabbix API at %s. Zabbix URL must be the frontend address, the folder holding api_jsonrpc.php (e.g. http://host/zabbix, or http://host/ when Zabbix is the site root).', $this->url)
				: '';

			throw new ApiException(sprintf('%s: unexpected response (HTTP %d).%s%s', $method, $code, $hint,
				$hint === '' ? ' '.mb_substr(trim(strip_tags((string) $raw)), 0, 200) : ''
			));
		}

		if (array_key_exists('error', $response)) {
			$e = $response['error'];

			$data = (string) ($e['data'] ?? '');
			$hint = '';

			if (stripos($data, 'Not authorized') !== false || stripos($data, 'Session terminated') !== false) {
				$hint = ' The API token is wrong, expired or disabled (Users > API tokens).';
			}
			elseif (stripos($data, 'No permissions to call') !== false
					|| stripos($data, 'do not have permission') !== false) {
				$hint = ' The token belongs to a user that is not Super admin.';
			}

			throw new ApiException(sprintf('%s: %s %s%s', $method, $e['message'] ?? '', $data, $hint));
		}

		return $response['result'];
	}
}
