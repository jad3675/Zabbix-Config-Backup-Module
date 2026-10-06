<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib\Dest;

/**
 * S3 and S3-compatible storage (AWS, MinIO, Wasabi, Backblaze B2, Ceph RGW...) with AWS Signature V4 over curl.
 * No SDK: PUT, GET, DELETE and ListObjectsV2 are all this needs.
 */
class S3 implements Remote {

	private string $endpoint;
	private string $region;
	private string $bucket;
	private string $prefix;
	private bool $path_style;
	private string $access_key;
	private string $secret_key;
	private string $sse;
	private ?string $proxy;
	private bool $verify_tls;

	// For tests: fixed clock.
	public ?int $now = null;

	public function __construct(array $d, string $secret_key, ?string $proxy = null) {
		$this->region = $d['region'] !== '' ? $d['region'] : 'us-east-1';
		$this->endpoint = rtrim($d['endpoint'] !== '' ? $d['endpoint'] : 'https://s3.'.$this->region.'.amazonaws.com', '/');
		$this->bucket = $d['bucket'];
		$this->prefix = $d['prefix'] !== '' ? $d['prefix'].'/' : '';
		$this->path_style = $d['path_style'];
		$this->access_key = $d['access_key'];
		$this->secret_key = $secret_key;
		$this->sse = $d['sse'];
		$this->proxy = $proxy;
		$this->verify_tls = (bool) ($d['verify_tls'] ?? true);

		if ($this->bucket === '' || $this->access_key === '' || $this->secret_key === '') {
			throw new \InvalidArgumentException('S3 destination needs a bucket, access key and secret key.');
		}

		if (!preg_match('~^https?://~', $this->endpoint)) {
			throw new \InvalidArgumentException('S3 endpoint must start with http:// or https://');
		}
	}

	public function put(string $local_file, string $name): void {
		$headers = ['content-type' => 'application/octet-stream'];

		if ($this->sse !== '') {
			$headers['x-amz-server-side-encryption'] = $this->sse;
		}

		$this->request('PUT', $this->prefix.$name, [], $headers, $local_file);
	}

	public function get(string $name, string $local_file): void {
		$this->request('GET', $this->prefix.$name, [], [], null, $local_file);
	}

	public function delete(string $name): void {
		$this->request('DELETE', $this->prefix.$name);
	}

	public function list(): array {
		$out = [];
		$token = null;

		do {
			$query = ['list-type' => '2', 'prefix' => $this->prefix];

			if ($token !== null) {
				$query['continuation-token'] = $token;
			}

			$xml = $this->request('GET', '', $query);
			$doc = @simplexml_load_string($xml);

			if ($doc === false) {
				throw new \RuntimeException('S3 list: unreadable response.');
			}

			foreach ($doc->Contents as $item) {
				$key = (string) $item->Key;
				$name = substr($key, strlen($this->prefix));

				if ($name !== '' && strpos($name, '/') === false) {
					$out[$name] = ['size' => (int) $item->Size, 'time' => strtotime((string) $item->LastModified)];
				}
			}

			$token = ((string) $doc->IsTruncated) === 'true' ? (string) $doc->NextContinuationToken : null;
		}
		while ($token !== null && $token !== '');

		return $out;
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

		return sprintf('Write, list and delete OK in s3://%s/%s (%d objects).', $this->bucket, $this->prefix,
			max(0, $count - 1)
		);
	}

	/*
	 * Signature V4.
	 */

	public function url(string $key, array $query = []): array {
		$parts = parse_url($this->endpoint);
		$host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
		$base_path = rtrim($parts['path'] ?? '', '/');

		if ($this->path_style) {
			$path = $base_path.'/'.$this->bucket.($key !== '' ? '/'.$key : '/');
		}
		else {
			$host = $this->bucket.'.'.$host;
			$path = $base_path.'/'.$key;
		}

		$path = implode('/', array_map('rawurlencode', explode('/', $path)));

		ksort($query, SORT_STRING);
		$canonical_query = implode('&', array_map(
			static fn($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v),
			array_keys($query), $query
		));

		return [$parts['scheme'].'://'.$host.$path.($canonical_query !== '' ? '?'.$canonical_query : ''),
			$host, $path, $canonical_query
		];
	}

	public function sign(string $method, string $host, string $path, string $canonical_query, array $headers,
			string $payload_hash): array {
		$now = $this->now ?? time();
		$amz_date = gmdate('Ymd\THis\Z', $now);
		$date = gmdate('Ymd', $now);

		$headers['host'] = $host;
		$headers['x-amz-date'] = $amz_date;
		$headers['x-amz-content-sha256'] = $payload_hash;
		ksort($headers, SORT_STRING);

		$canonical_headers = '';
		foreach ($headers as $k => $v) {
			$canonical_headers .= $k.':'.trim((string) $v)."\n";
		}
		$signed_headers = implode(';', array_keys($headers));

		$canonical_request = implode("\n", [$method, $path, $canonical_query, $canonical_headers, $signed_headers,
			$payload_hash
		]);

		$scope = $date.'/'.$this->region.'/s3/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n".$amz_date."\n".$scope."\n".hash('sha256', $canonical_request);

		$key = hash_hmac('sha256', $date, 'AWS4'.$this->secret_key, true);
		$key = hash_hmac('sha256', $this->region, $key, true);
		$key = hash_hmac('sha256', 's3', $key, true);
		$key = hash_hmac('sha256', 'aws4_request', $key, true);

		$headers['authorization'] = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->access_key, $scope, $signed_headers, hash_hmac('sha256', $string_to_sign, $key)
		);

		return $headers;
	}

	private function request(string $method, string $key, array $query = [], array $headers = [],
			?string $upload = null, ?string $download = null): string {
		[$url, $host, $path, $canonical_query] = $this->url($key, $query);

		$payload_hash = $upload !== null ? hash_file('sha256', $upload) : hash('sha256', '');
		$headers = $this->sign($method, $host, $path, $canonical_query, $headers, $payload_hash);
		unset($headers['host']);

		$ch = curl_init($url);
		$opts = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => array_map(static fn($k, $v) => $k.': '.$v, array_keys($headers), $headers),
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT => 3600,
			CURLOPT_SSL_VERIFYPEER => $this->verify_tls,
			CURLOPT_SSL_VERIFYHOST => $this->verify_tls ? 2 : 0
		];

		$in = $out = null;

		if ($upload !== null) {
			$in = fopen($upload, 'rb');
			$opts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => filesize($upload)];
			$opts[CURLOPT_HTTPHEADER][] = 'Expect:';
		}

		if ($download !== null) {
			$out = fopen($download.'.part', 'wb');
			$opts[CURLOPT_FILE] = $out;
		}
		else {
			$opts[CURLOPT_RETURNTRANSFER] = true;
		}

		if ($this->proxy !== null && $this->proxy !== '') {
			$opts[CURLOPT_PROXY] = $this->proxy;
		}

		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($in) {
			fclose($in);
		}

		if ($out) {
			fclose($out);
		}

		if ($body === false) {
			@unlink((string) $download.'.part');

			throw new \RuntimeException(sprintf('S3 %s %s: %s', $method, $key ?: '/', $error));
		}

		if ($code < 200 || $code >= 300) {
			$text = $download !== null ? (string) @file_get_contents($download.'.part') : (string) $body;
			@unlink((string) $download.'.part');

			$message = preg_match('~<Code>(.*?)</Code>.*?<Message>(.*?)</Message>~s', $text, $m)
				? $m[1].': '.$m[2]
				: mb_substr(trim(strip_tags($text)), 0, 200);

			throw new \RuntimeException(sprintf('S3 %s %s: HTTP %d %s', $method, $key ?: '/', $code, $message));
		}

		if ($download !== null) {
			rename($download.'.part', $download);

			return '';
		}

		return (string) $body;
	}
}
