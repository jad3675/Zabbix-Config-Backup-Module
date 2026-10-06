<?php declare(strict_types = 0);

namespace Modules\ConfigBackup\Lib;

class SnapshotWriter {

	private string $id;
	private string $tmp;
	private string $final;
	private $gz = null;
	private ?string $type = null;
	private array $index = [];

	public function __construct(string $id, string $tmp, string $final) {
		$this->id = $id;
		$this->tmp = $tmp;
		$this->final = $final;
	}

	public function getId(): string {
		return $this->id;
	}

	public function beginType(string $type): void {
		$this->endType();
		$this->type = $type;
		$this->index = [];
		$this->gz = gzopen($this->tmp.'/'.$type.'.jsonl.gz', 'wb6');
	}

	public function add(string $id, string $name, $data, ?string $tech = null): void {
		// "id" must be the first key: Store::getObjects() finds lines by prefix.
		$line = json_encode(['id' => $id, 'name' => $name, 'data' => $data],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		gzwrite($this->gz, $line."\n");

		$entry = ['id' => $id, 'name' => $name];
		if ($tech !== null && $tech !== $name) {
			$entry['tech'] = $tech;
		}
		$this->index[] = $entry;
	}

	/**
	 * @return int  objects written for the type
	 */
	public function endType(): int {
		if ($this->type === null) {
			return 0;
		}

		gzclose($this->gz);
		Store::writeJson($this->tmp.'/'.$this->type.'.index.json', $this->index);

		$count = count($this->index);
		$this->type = null;
		$this->gz = null;
		$this->index = [];

		return $count;
	}

	public function commit(array $meta): void {
		$this->endType();
		Store::writeJson($this->tmp.'/meta.json', $meta);

		if (!rename($this->tmp, $this->final)) {
			throw new \RuntimeException('Cannot move snapshot into place: '.$this->final);
		}
	}

	public function abort(): void {
		if ($this->gz !== null) {
			gzclose($this->gz);
		}

		if (is_dir($this->tmp)) {
			Store::rmTree($this->tmp);
		}
	}
}
