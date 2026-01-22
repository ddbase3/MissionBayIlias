<?php declare(strict_types=1);

namespace MissionBayIlias\Api;

/**
 * Interface IObjectTreeResolver
 *
 * Global ILIAS tree / reference resolver.
 * NOT content-kind specific.
 *
 * Used by the embedding extractor to understand where an object is mounted in the tree,
 * and to support subtree-based filtering during retrieval by storing the *ancestor path ref_ids*.
 */
interface IObjectTreeResolver {

	/**
	 * Returns all ref_ids by which this obj_id is mounted in the ILIAS tree.
	 *
	 * @return int[] ref_ids
	 */
	public function getRefIdsByObjId(int $objId): array;

	/**
	 * Returns the merged list of all ref_ids that occur in ANY ancestor path
	 * (root -> ... -> mount) of ANY mount point of the given obj_id.
	 *
	 * This supports checks like: "Is element X contained in the subtree of container ref_id Y?"
	 * by storing all ancestor ref_ids for X and then checking whether Y is included.
	 *
	 * Example:
	 * - mount path: "1.4599.4619"
	 * - result: [1, 4599, 4619]
	 *
	 * @return int[] ref_ids (unique)
	 */
	public function getAllAncestorPathRefIdsByObjId(int $objId): array;
}
