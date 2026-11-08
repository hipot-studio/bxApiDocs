<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * One page of the journal of changes: the records in the order they were published, the position that
 * continues the reading, until when that position is accepted, and whatever the page could not
 * describe.
 *
 * It extends the canonical ListResponse so the answer is serialised by the same path as every other
 * V3 listing; the four fields beside the items are what the stock envelope cannot carry.
 */
final class ChangesListResponse extends ListResponse
{
	/**
	 * @param DtoCollection $items the records of the page, ChangeDto
	 * @param bool $hasMore whether the journal goes on; decided by the position against the watermark
	 *        of the safe window and not by how many records the page ended up with
	 * @param string $nextCursor the position the next page is asked for by, answered on the last page
	 *        as well. It moves past every record the page walked, including the ones that fell out of
	 *        it, so asking again with the same cursor is safe and asking with this one never replays
	 *        what was just handed over.
	 * @param DateTime $cursorExpiresAt when the position stops being accepted. Published so that a
	 *        client which has been switched off can tell an expired cursor from a lost one before it
	 *        spends a request learning it.
	 * @param array<int, array{resourceType: string, resourceId: int, reason: string}> $warnings what
	 *        the page could not describe. The kind and the identifier of the resource are both
	 *        published: without the identifier a client could not read the skipped object back.
	 */
	public function __construct(
		DtoCollection $items,
		public bool $hasMore,
		public string $nextCursor,
		public DateTime $cursorExpiresAt,
		public array $warnings,
	)
	{
		parent::__construct($items);
	}
}
