<?php declare(strict_types=1);

namespace MissionBayIlias\Service;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;

/**
 * ILIAS tree / reference resolver based on DB tables:
 * - object_reference (ref_id -> obj_id)
 * - tree (nested set, keyed by ref_ids in "child")
 */
final class IliasObjectTreeResolver implements IObjectTreeResolver {

	public function __construct(
		private readonly IDatabase $db
	) {
	}

	/**
	 * {@inheritdoc}
	 */
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

	/**
	 * {@inheritdoc}
	 */
	public function getAllSubtreeRefIdsByObjId(int $objId): array {
		$mountRefIds = $this->getRefIdsByObjId($objId);
		if ($mountRefIds === []) {
			return [];
		}

		// 1) Resolve nested-set bounds for each mount point.
		$bounds = $this->getBoundsByRefIds($mountRefIds);
		if ($bounds === []) {
			return [];
		}

		// 2) Merge to minimal set of non-overlapping intervals (performance).
		$intervals = $this->mergeIntervals($bounds);

		// 3) Fetch all subtree children for all merged intervals.
		$whereParts = [];
		foreach ($intervals as $it) {
			$whereParts[] = '(tree = ' . (int)$it['tree'] . ' AND lft >= ' . (int)$it['lft'] . ' AND rgt <= ' . (int)$it['rgt'] . ')';
		}

		$rows = $this->db->multiQuery(
			'SELECT child
			 FROM tree
			 WHERE ' . implode(' OR ', $whereParts)
		);

		$refIds = [];
		foreach ($rows as $row) {
			$rid = (int)($row['child'] ?? 0);
			if ($rid > 0) {
				$refIds[$rid] = true;
			}
		}

		$unique = array_keys($refIds);
		sort($unique, SORT_NUMERIC);

		return $unique;
	}

	/**
	 * @param int[] $refIds
	 * @return array<int, array{tree:int,lft:int,rgt:int}>
	 */
	private function getBoundsByRefIds(array $refIds): array {
		$refIds = array_values(array_unique(array_map('intval', $refIds)));
		$refIds = array_values(array_filter($refIds, static fn(int $v) => $v > 0));
		if ($refIds === []) {
			return [];
		}

		$rows = $this->db->multiQuery(
			'SELECT tree, child, lft, rgt
			 FROM tree
			 WHERE child IN (' . implode(',', $refIds) . ')'
		);

		$bounds = [];
		foreach ($rows as $row) {
			$tree = (int)($row['tree'] ?? 0);
			$lft = (int)($row['lft'] ?? 0);
			$rgt = (int)($row['rgt'] ?? 0);
			if ($tree > 0 && $lft > 0 && $rgt > 0 && $lft <= $rgt) {
				$bounds[] = [
					'tree' => $tree,
					'lft' => $lft,
					'rgt' => $rgt,
				];
			}
		}

		return $bounds;
	}

	/**
	 * Merge overlapping / contained nested-set intervals per tree.
	 *
	 * @param array<int, array{tree:int,lft:int,rgt:int}> $bounds
	 * @return array<int, array{tree:int,lft:int,rgt:int}>
	 */
	private function mergeIntervals(array $bounds): array {
		if ($bounds === []) {
			return [];
		}

		usort($bounds, static function(array $a, array $b): int {
			if ($a['tree'] !== $b['tree']) {
				return $a['tree'] <=> $b['tree'];
			}
			if ($a['lft'] !== $b['lft']) {
				return $a['lft'] <=> $b['lft'];
			}
			// For same lft, larger rgt first (so containers come before contained)
			return $b['rgt'] <=> $a['rgt'];
		});

		$out = [];
		$cur = null;

		foreach ($bounds as $b) {
			if ($cur === null) {
				$cur = $b;
				continue;
			}

			if ($b['tree'] !== $cur['tree']) {
				$out[] = $cur;
				$cur = $b;
				continue;
			}

			// Disjoint interval -> flush current
			if ($b['lft'] > $cur['rgt']) {
				$out[] = $cur;
				$cur = $b;
				continue;
			}

			// Overlap/containment -> extend current rgt if needed
			if ($b['rgt'] > $cur['rgt']) {
				$cur['rgt'] = $b['rgt'];
			}
		}

		if ($cur !== null) {
			$out[] = $cur;
		}

		return $out;
	}
}
