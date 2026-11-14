<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * One relation of one object, written by the provider.
 *
 * The obligations of the fields belong to the provider, not to this carrier: it validates nothing and
 * throws nothing, so a title with markup or an unstable relationId reaches Disk as a contract defect of
 * the provider and is caught by the contract test set.
 */
final class RelationData
{
	/**
	 * @param string $relationId stable on the portal: "<source>:<key>", e.g. "attachment:900", "im_link:123"
	 * @param string $entityType one of the allowed answer types of the provider and of the request
	 * @param string $entityId identifier of the entity as a string
	 * @param string $entityTitle no HTML and no BB codes, up to 255 characters, an empty string is forbidden
	 * @param string|null $entityUrl absolute path from the portal root ("/...") or a full URL; null - the entity has no page
	 * @param int|null $attachedObjectId id of the attachment the relation describes, null otherwise
	 */
	public function __construct(
		public readonly string $relationId,
		public readonly string $entityType,
		public readonly string $entityId,
		public readonly string $entityTitle,
		public readonly ?string $entityUrl,
		public readonly RelationType $relationType,
		public readonly ?int $attachedObjectId = null,
	)
	{
	}
}
