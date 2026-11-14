<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject\ActivityFile;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;

/**
 * One page of the global list of activities, read for an administrator of the portal.
 *
 * The storage selects the identifiers of the page under the filter and the sort, with the offset and the limit
 * of the page ({@see ActivityRepository::getIds()}), and the page is assembled for them at once
 * ({@see ActivityReader::getManyUnchecked()}). NO RIGHT TO AN ACTIVITY IS ASKED: the caller,
 * {@see \Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider::getList()}, lets nobody but an administrator of
 * the portal in. A provider may hide from the administrator in a read of one activity what the page shows; a check
 * here would only make the page short.
 *
 * The page is an offset into the list at the moment of the call; an activity written between two calls may move
 * the pages, as any offset does.
 *
 * @internal
 */
final class ActivityListReader
{
	/** Result item key: the {@see Activity} read. */
	public const ITEM_ACTIVITY = ActivityReader::DATA_ACTIVITY;

	/** Result item key: the {@see ActivityFile} metadata of its files, null where the select left them out. */
	public const ITEM_FILES = ActivityReader::DATA_FILES;

	private ?ActivityReader $reader = null;

	private ?ActivityRepository $repository = null;

	/**
	 * @param string[] $select as {@see ActivityReader::get()} takes it
	 * @return array<int, array{activity: Activity, files: ActivityFile[]|null}> in the order of the sort
	 */
	public function list(
		int $userId,
		ActivityFilter $filter,
		ActivitySort $sort,
		int $limit,
		int $offset,
		array $select = [],
	): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$pageIds = $this->getRepository()->getIds($filter, $sort, $limit, max(0, $offset));

		return $this->getReader()->getManyUnchecked($userId, $pageIds, $select);
	}

	private function getReader(): ActivityReader
	{
		return $this->reader ??= new ActivityReader();
	}

	private function getRepository(): ActivityRepository
	{
		return $this->repository ??= new ActivityRepository();
	}
}
