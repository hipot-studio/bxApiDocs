<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ChangeDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\ChangesListRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\ChangesListResponse;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalReader;
use Bitrix\Disk\Internal\Service\Sync\Position\JournalCursor;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * disk.sync.changes.list - one page of the journal of changes.
 *
 * What a page holds is decided by the reader, and the position it is asked for by is decoded by the
 * position itself: a cursor of another user, of another application of the same user, of another kind
 * or damaged in any way is refused there, and so is one that has outlived the journal. What is left
 * here is naming the pair the position belongs to and handing the answer of the reader to the wire.
 */
#[DtoType(ChangeDto::class)]
final class Changes extends BaseSyncController
{
	public function listAction(ChangesListRequest $request): ChangesListResponse
	{
		$cursor = JournalCursor::decode($request->getCursor(), $this->resolveUserId(), $this->resolveAppId());

		$page = (new JournalReader())->page($cursor, $request->getLimit(), $request->getSections());

		return new ChangesListResponse(
			self::collectionOf($page['items']),
			$page['hasMore'],
			$page['cursor']->encode(),
			$page['cursor']->getExpiresAt(),
			$page['warnings'],
		);
	}

	/**
	 * @param ChangeDto[] $items
	 */
	private static function collectionOf(array $items): DtoCollection
	{
		$collection = new DtoCollection(ChangeDto::class);
		foreach ($items as $item)
		{
			$collection->add($item);
		}

		return $collection;
	}
}
