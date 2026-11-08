<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * One page of the initial fetch: the objects of the page, the folders they hang under, the position
 * that continues the fetch and whatever the page could not deliver.
 *
 * It extends the canonical ListResponse so the answer is serialised by the same path as every other
 * V3 listing; the three fields beside the items are what the stock envelope cannot carry.
 *
 * Folders travel in a section of their own and are not counted into the size of the page. Counting
 * them in would make the size of a page unpredictable - it would depend on how deep the objects of
 * that page happen to lie - and would leave the client unable to apply the consequences of an
 * operation on a folder to its paths until it had read the whole fetch to the end.
 */
final class SnapshotListResponse extends ListResponse
{
	/**
	 * @param DtoCollection $items the objects of the page, ObjectCardDto
	 * @param DtoCollection $folders every folder the page needs the client to know, ObjectCardDto of
	 *        folders: the readable folders above its objects, and the links of the user that the
	 *        paths of the page pass through. Not counted into the size of the page and deduplicated
	 *        inside it. A folder repeated on a later page is harmless - applying the card of a folder
	 *        is idempotent.
	 * @param bool $hasMore whether the fetch goes on; decided by a read that came back empty and not
	 *        by a page that came back short
	 * @param string $nextSnapshotToken the position the next page is asked for by, answered even on
	 *        the last page
	 * @param array<int, array{resourceType: string, resourceId: int, reason: string}> $warnings what
	 *        the page could not deliver. The kind and the identifier of the resource are both
	 *        published: without the identifier a client could not read the skipped object back by
	 *        reconciliation.
	 */
	public function __construct(
		DtoCollection $items,
		public DtoCollection $folders,
		public bool $hasMore,
		public string $nextSnapshotToken,
		public array $warnings,
	)
	{
		parent::__construct($items);
	}
}
