<?php declare(strict_types=1);

namespace MissionBayIlias\Api;

/**
 * ContentBatch
 *
 * @property ContentUnit[] $units
 */
final class ContentBatch {

	/**
	 * @param ContentUnit[] $units
	 */
	public function __construct(
		public readonly array $units,
		public readonly ContentCursor $nextCursor
	) {}
}
