<?php declare(strict_types=1);

namespace MissionBayIlias\Api;

/**
 * Interface IObjectTreeResolver
 *
 * Global ILIAS tree / reference resolver.
 * NOT content-kind specific.
 *
 * Used by the embedding extractor to understand where an object is mounted in the tree,
 * and to support subtree-based filtering during retrieval.
 */
interface IObjectTreeResolver {

	/**
	 * Returns all ref_ids by which this obj_id is mounted in the ILIAS tree.
	 *
	 * @return int[] ref_ids
	 */
	public function getRefIdsByObjId(int $objId): array;

	/**
	 * Returns the merged list of all ref_ids that occur in ANY subtree of ANY mount path
	 * of the given obj_id.
	 *
	 * This supports checks like: "Is element X contained in the subtree of container obj Y?"
	 * even if Y is mounted multiple times (multiple ref_ids).
	 *
	 * @return int[] ref_ids (unique)
	 */
	public function getAllSubtreeRefIdsByObjId(int $objId): array;
}
