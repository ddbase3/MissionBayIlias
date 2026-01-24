<?php declare(strict_types=1);

namespace MissionBayIlias\Dto;

/**
 * ContentBatchDto
 *
 * @property ContentUnitDto[] $units
 */
final class ContentBatchDto {

	/**
	 * @param ContentUnitDto[] $units
	 */
	public function __construct(
		public readonly array $units,
		public readonly ContentCursorDto $nextCursor
	) {}
}
