<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * An attachment row of a connector class of the provider, passed for confirmation.
 *
 * The provider answers every candidate with a confirmation or with its absence.
 *
 * @see RelationProviderInterface::describeVisible()
 */
final class AttachmentCandidate
{
	/**
	 * @param int $objectId object of the page, not the real object the attachment points at
	 * @param string $connectorClass class-string<\Bitrix\Disk\Uf\Connector>
	 */
	public function __construct(
		public readonly int $attachedObjectId,
		public readonly int $objectId,
		public readonly ?int $versionId,
		public readonly string $connectorClass,
		public readonly int $entityId,
	)
	{
	}
}
