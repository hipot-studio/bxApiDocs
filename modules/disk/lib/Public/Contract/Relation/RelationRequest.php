<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * One call per page, written by Disk.
 *
 * @see RelationProviderInterface::describeVisible()
 */
final class RelationRequest
{
	/**
	 * @param int $userId explicit reader; the global $USER is not used
	 * @param bool $confirmOnly true - only the confirmation of the candidates is asked for
	 * @param list<string>|null $entityTypes allowed answer types to return; null - every allowed type, passed
	 *        only to an employee without a session filter; for extranet and collaber always a list. Not used
	 *        in the confirm only mode
	 * @param list<ObjectRef> $objects full mode - objects of the page, up to 50; confirm only mode - objects
	 *        of the confirmation, up to 100
	 * @param list<AttachmentCandidate> $attachments candidates of the declared connector classes of the
	 *        provider: no more than relationLimit + 1 per object and class; candidates of the confirmation are
	 *        always all of them within that limit, candidates of relations - up to 500 per page in full mode
	 * @param array<int, list<class-string>> $truncatedCandidates objectId to the connector classes whose
	 *        candidates of the object are cut by the per class limit; full mode only, empty in confirm only
	 * @param int $relationLimit limit of relations per object, set by Disk
	 */
	public function __construct(
		public readonly int $userId,
		public readonly ReaderCategory $reader,
		public readonly bool $confirmOnly,
		public readonly ?array $entityTypes,
		public readonly array $objects,
		public readonly array $attachments,
		public readonly array $truncatedCandidates,
		public readonly int $relationLimit,
	)
	{
	}
}
