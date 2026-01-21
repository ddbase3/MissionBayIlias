<?php declare(strict_types=1);

namespace MissionBayIlias\Api;

/**
 * ContentCursor
 *
 * Cursor = (changed_at, changed_id) tie-breaker.
 * Serialized as "YYYY-mm-dd HH:ii:ss|<id>".
 */
final class ContentCursor {

	public function __construct(
		public readonly string $changedAt,
		public readonly int $changedId
	) {}

	public static function fromString(?string $raw): self {
		$raw = trim((string)$raw);
		if ($raw === '' || $raw === '0') {
			return new self('1970-01-01 00:00:00', 0);
		}

		$parts = explode('|', $raw, 2);
		$ts = trim((string)($parts[0] ?? ''));
		$id = (int)($parts[1] ?? 0);

		if ($ts === '') {
			$ts = '1970-01-01 00:00:00';
		}

		return new self($ts, $id);
	}

	public function toString(): string {
		return $this->changedAt . '|' . (string)$this->changedId;
	}
}
