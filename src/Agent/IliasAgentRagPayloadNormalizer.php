<?php declare(strict_types=1);

namespace MissionBayIlias\Agent;

use MissionBay\Api\IAgentRagPayloadNormalizer;
use MissionBay\Dto\AgentEmbeddingChunk;

/**
 * IliasAgentRagPayloadNormalizer
 *
 * ILIAS normalizer for Qdrant payloads.
 *
 * Goal: mirror XRM normalizer shape so downstream logic can stay generic.
 *
 * Agreements:
 * - content_uuid is canonical: 32 HEX chars, UPPERCASE, no dashes
 * - collectionKey canonical is 'ilias'
 * - backend collection name is 'base3ilias_content_v1'
 * - unknown domain metadata is preserved as payload.meta (not indexed)
 */
final class IliasAgentRagPayloadNormalizer implements IAgentRagPayloadNormalizer {

	private const CANONICAL_COLLECTION_KEY = 'ilias';
	private const BACKEND_COLLECTION = 'base3ilias_content_v1';
	private const VECTOR_SIZE = 1024;
	private const DISTANCE = 'Cosine';

	private bool $debug = false;

	public function setDebug(bool $debug): void {
		$this->debug = $debug;
	}

	public function getCollectionKeys(): array {
		return [self::CANONICAL_COLLECTION_KEY];
	}

	public function getBackendCollectionName(string $collectionKey): string {
		$this->mapToCanonicalCollectionKey($collectionKey);
		return self::BACKEND_COLLECTION;
	}

	public function getVectorSize(string $collectionKey): int {
		$this->mapToCanonicalCollectionKey($collectionKey);
		return self::VECTOR_SIZE;
	}

	public function getDistance(string $collectionKey): string {
		$this->mapToCanonicalCollectionKey($collectionKey);
		return self::DISTANCE;
	}

	public function getSchema(string $collectionKey): array {
		$this->mapToCanonicalCollectionKey($collectionKey);

		return [
			'text' => ['type' => 'text', 'index' => false],
			'hash' => ['type' => 'keyword', 'index' => true],
			'collection_key' => ['type' => 'keyword', 'index' => true],

			// canonical delete / replace key
			'content_uuid' => ['type' => 'keyword', 'index' => true],

			'chunktoken' => ['type' => 'keyword', 'index' => true],
			'chunk_index' => ['type' => 'integer', 'index' => true],

			'content_id_hex' => ['type' => 'keyword', 'index' => false],
			'source_kind' => ['type' => 'keyword', 'index' => true],

			'type' => ['type' => 'keyword', 'index' => true],

			'source_locator' => ['type' => 'keyword', 'index' => false],
			'container_obj_id' => ['type' => 'integer', 'index' => true],
			'source_int_id' => ['type' => 'integer', 'index' => false],

			'num_chunks' => ['type' => 'integer', 'index' => false],
			'read_roles' => ['type' => 'integer', 'index' => true],
			'mount_ref_ids' => ['type' => 'integer', 'index' => true],

			'ancestor_ref_ids' => ['type' => 'integer', 'index' => true],

			'title' => ['type' => 'text', 'index' => true],
		];
	}

	public function validate(AgentEmbeddingChunk $chunk): void {
		$canonical = $this->mapToCanonicalCollectionKey((string)$chunk->collectionKey);

		if (!is_int($chunk->chunkIndex) || $chunk->chunkIndex < 0) {
			throw new \RuntimeException('chunkIndex must be >= 0.');
		}

		if (trim((string)$chunk->text) === '') {
			throw new \RuntimeException('text must be non-empty.');
		}

		if (trim((string)$chunk->hash) === '') {
			throw new \RuntimeException('hash must be non-empty.');
		}

		if (!is_array($chunk->metadata)) {
			throw new \RuntimeException('metadata must be an array.');
		}

		$contentUuid = $chunk->metadata['content_uuid'] ?? null;
		if (
			!is_string($contentUuid) ||
			strlen($contentUuid) !== 32 ||
			!ctype_xdigit($contentUuid)
		) {
			throw new \RuntimeException(
				"metadata.content_uuid must be 32 hex chars (uppercase, no dashes)."
			);
		}

		$this->assertIntArrayMeta($chunk->metadata, 'read_roles');
		$this->assertIntArrayMeta($chunk->metadata, 'mount_ref_ids');
		$this->assertIntArrayMeta($chunk->metadata, 'ancestor_ref_ids');

		if (array_key_exists('num_chunks', $chunk->metadata)) {
			$n = $chunk->metadata['num_chunks'];
			if (!is_int($n) || $n <= 0) {
				throw new \RuntimeException("num_chunks must be a positive int.");
			}
		}

		$chunk->collectionKey = $canonical;
		$chunk->metadata['content_uuid'] = strtoupper($contentUuid);
	}

	public function buildPayload(AgentEmbeddingChunk $chunk): array {
		$this->validate($chunk);
		$meta = $chunk->metadata;

		$payload = [
			'text' => trim((string)$chunk->text),
			'hash' => trim((string)$chunk->hash),
			'collection_key' => self::CANONICAL_COLLECTION_KEY,
			'content_uuid' => $meta['content_uuid'],
			'chunktoken' => $this->buildChunkToken($chunk->hash, $chunk->chunkIndex),
			'chunk_index' => $chunk->chunkIndex,
		];

		$this->addIfString($payload, 'content_id_hex', $meta['content_id_hex'] ?? null);
		$this->addIfString($payload, 'source_kind', $meta['source_kind'] ?? null);
		$this->addIfString($payload, 'type', $meta['type'] ?? null);
		$this->addIfString($payload, 'source_locator', $meta['source_locator'] ?? null);
		$this->addIfInt($payload, 'container_obj_id', $meta['container_obj_id'] ?? null);
		$this->addIfInt($payload, 'source_int_id', $meta['source_int_id'] ?? null);
		$this->addIfString($payload, 'title', $meta['title'] ?? null);
		$this->addIfInt($payload, 'num_chunks', $meta['num_chunks'] ?? null);

		// Keep behavior for these: only include if non-empty
		foreach (['read_roles', 'mount_ref_ids'] as $k) {
			$arr = $this->normalizeIntArray($meta[$k] ?? null);
			if ($arr) {
				$payload[$k] = $arr;
			}
		}

		$payload['ancestor_ref_ids'] = $this->normalizeIntArray($meta['ancestor_ref_ids'] ?? []);

		$metaOut = $this->collectMeta($meta, array_keys($payload));
		if ($metaOut) {
			$payload['meta'] = $metaOut;
		}

		return $payload;
	}

	// ---------------------------------------------------------

	private function mapToCanonicalCollectionKey(string $key): string {
		$key = strtolower(trim($key));
		if ($key !== self::CANONICAL_COLLECTION_KEY) {
			throw new \InvalidArgumentException('Invalid collectionKey for ILIAS.');
		}
		return self::CANONICAL_COLLECTION_KEY;
	}

	private function buildChunkToken(string $hash, int $idx): string {
		return $idx > 0 ? "{$hash}-{$idx}" : $hash;
	}

	private function assertIntArrayMeta(array $meta, string $key): void {
		if (isset($meta[$key]) && !is_array($meta[$key])) {
			throw new \RuntimeException("{$key} must be array.");
		}
	}

	private function normalizeIntArray(mixed $v): array {
		if ($v === null) return [];
		if (!is_array($v)) throw new \RuntimeException('Expected array.');
		return array_values(array_unique(array_filter(array_map('intval', $v), fn($i) => $i > 0)));
	}

	private function addIfString(array &$p, string $k, mixed $v): void {
		if (is_string($v) && trim($v) !== '') $p[$k] = trim($v);
	}

	private function addIfInt(array &$p, string $k, mixed $v): void {
		if (is_int($v) && $v > 0) $p[$k] = $v;
	}

	private function collectMeta(array $meta, array $known): array {
		return array_diff_key($meta, array_flip($known));
	}
}
