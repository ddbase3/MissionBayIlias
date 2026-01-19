<?php declare(strict_types=1);

namespace MissionBayIlias\Agent;

use MissionBay\Api\IAgentRagPayloadNormalizer;
use MissionBay\Dto\AgentEmbeddingChunk;

final class IliasAgentRagPayloadNormalizer implements IAgentRagPayloadNormalizer {

	public function getCollectionKeys(): array {
		// TODO implement
		return [];
	}

	public function getBackendCollectionName(string $collectionKey): string {
		// TODO implement
		return '';
	}

	public function getVectorSize(string $collectionKey): int {
		// TODO implement
		return 0;
	}

	public function getDistance(string $collectionKey): string {
		// TODO implement
		return '';
	}

	public function getSchema(string $collectionKey): array {
		// TODO implement
		return [];
	}

	public function validate(AgentEmbeddingChunk $chunk): void {
		// TODO implement
	}

	public function buildPayload(AgentEmbeddingChunk $chunk): array {
		// TODO implement
		return [];
	}
}
