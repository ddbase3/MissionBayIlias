<?php declare(strict_types=1);

namespace MissionBayIlias\Dto;

/**
 * ContentUnitDto
 *
 * Normalized content unit record emitted by providers.
 */
final class ContentUnitDto {

	public function __construct(
		public readonly string $sourceSystem,
		public readonly string $sourceKind,
		public readonly string $sourceLocator,
		public readonly ?int $containerObjId,
		public readonly ?int $sourceIntId,
		public readonly ?string $title,
		public readonly ?string $description,
		public readonly ?string $contentUpdatedAt,
		public readonly ?string $contentVersionToken
	) {}
}
