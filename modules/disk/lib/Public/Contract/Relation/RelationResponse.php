<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * The answer of the provider to one page.
 *
 * @see RelationProviderInterface::describeVisible()
 */
final class RelationResponse
{
	/**
	 * @param array<int, list<RelationData>> $relations key - objectId of the object of the page, not
	 *        realObjectId; no more than relationLimit relations per object, only types of the request;
	 *        empty in the confirm only mode
	 * @param array<int, true> $hasMoreRelations key - objectId; present when the object has more relations of
	 *        the requested types than relationLimit, or the provider got relationLimit + 1 candidates of one
	 *        class of a requested type for the object and did not read the rest itself
	 * @param list<int> $confirmedAttachments attachedObjectId of the attachments whose entity the reader sees;
	 *        independent of relations and of the mode of the call; a provider that reads the rest of a
	 *        truncated object itself may answer with ids that were not among the candidates
	 */
	public function __construct(
		public readonly array $relations = [],
		public readonly array $hasMoreRelations = [],
		public readonly array $confirmedAttachments = [],
	)
	{
	}
}
