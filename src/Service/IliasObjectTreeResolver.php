<?php declare(strict_types=1);

namespace MissionBayIlias\Service;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;

/**
 * ILIAS tree / reference resolver based on DB tables:
 * - object_reference (ref_id -> obj_id)
 * - tree (child ref_id rows with "path" representing root->...->child)
 *
 * Provides mount ref_ids and merged ancestor-path ref_ids for filtering.
 */
final class IliasObjectTreeResolver implements IObjectTreeResolver {

	public function __construct(
		private readonly IDatabase $db
	) {
	}

	public function getRefIdsByObjId(int $objId): array {
		$rows = $this->db->multiQuery(
			'SELECT ref_id
			 FROM object_reference
			 WHERE obj_id = ' . (int)$objId . '
			   AND deleted IS NULL
			 ORDER BY ref_id ASC'
		);

		$refIds = [];
		foreach ($rows as $row) {
			$rid = (int)($row['ref_id'] ?? 0);
			if ($rid > 0) {
				$refIds[] = $rid;
			}
		}

		return $refIds;
	}

	public function getAllAncestorPathRefIdsByObjId(int $objId): array {
		$mountRefIds = $this->getRefIdsByObjId($objId);
		if ($mountRefIds === []) {
			return [];
		}

		$paths = $this->getPathsByRefIds($mountRefIds);
		if ($paths === []) {
			// Hard fallback: if path is missing for some reason, at least return mounts.
			$unique = array_values(array_unique(array_map('intval', $mountRefIds)));
			sort($unique, SORT_NUMERIC);
			return $unique;
		}

		$refIds = [];
		foreach ($paths as $p) {
			foreach ($this->parsePathRefIds((string)$p['path']) as $rid) {
				$refIds[$rid] = true;
			}
		}

		$unique = array_keys($refIds);
		sort($unique, SORT_NUMERIC);

		return $unique;
	}

	/**
	 * @param int[] $refIds
	 * @return array<int, array{tree:int,child:int,path:string}>
	 */
	private function getPathsByRefIds(array $refIds): array {
		$refIds = array_values(array_unique(array_map('intval', $refIds)));
		$refIds = array_values(array_filter($refIds, static fn(int $v) => $v > 0));
		if ($refIds === []) {
			return [];
		}

		$rows = $this->db->multiQuery(
			'SELECT tree, child, path
			 FROM tree
			 WHERE child IN (' . implode(',', $refIds) . ')
			   AND path IS NOT NULL
			   AND path <> \'\''
		);

		$out = [];
		foreach ($rows as $row) {
			$tree = (int)($row['tree'] ?? 0);
			$child = (int)($row['child'] ?? 0);
			$path = (string)($row['path'] ?? '');

			if ($tree > 0 && $child > 0 && $path !== '') {
				$out[] = [
					'tree' => $tree,
					'child' => $child,
					'path' => $path,
				];
			}
		}

		return $out;
	}

	/**
	 * Parses a tree.path like "1.4599.4619" into [1,4599,4619].
	 *
	 * @return int[]
	 */
	private function parsePathRefIds(string $path): array {
		$path = trim($path);
		if ($path === '') {
			return [];
		}

		$parts = explode('.', $path);
		$out = [];
		foreach ($parts as $part) {
			$rid = (int)trim($part);
			if ($rid > 0) {
				$out[] = $rid;
			}
		}

		return $out;
	}
}
