<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IContentProvider;

abstract class AbstractContentProvider implements IContentProvider {

	public function __construct(protected readonly IDatabase $db) {}

	protected function queryOne(string $sql): ?array {
		return $this->db->singleQuery($sql);
	}

	protected function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	protected function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
