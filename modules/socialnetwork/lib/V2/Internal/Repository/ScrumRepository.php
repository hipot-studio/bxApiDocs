<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Internals\Group\GroupEntityCollection;
use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTagTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;
use Bitrix\Socialnetwork\V2\Internal\Repository\Workgroup\WorkgroupQueryTrait;

class ScrumRepository implements ScrumRepositoryInterface
{
	use WorkgroupQueryTrait;

	public function __construct(
		private readonly ExtranetUserService $extranetUserService,
	)
	{
	}

	public function getListRaw(
		array $select = ['*'],
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		?int $currentUserId = null,
		?int $contextUserId = null,
		bool $isAdmin = false,
		?int $pinUserId = null,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::Common,
	): GroupEntityCollection
	{
		$query = $this->createListQuery(
			select: $select,
			filter: $filter,
			sort: $sort,
			offset: $offset,
			limit: $limit,
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			isAdmin: $isAdmin,
			pinUserId: $pinUserId,
			pinMode: $pinMode,
		);

		return $query->exec()->fetchCollection();
	}

	public function getCount(
		?ConditionTree $filter = null,
		?int $currentUserId = null,
		bool $isAdmin = false,
	): int
	{
		$query = $this->createCountQuery(
			filter: $filter,
			currentUserId: $currentUserId,
			isAdmin: $isAdmin,
		);

		return $query->exec()->getCount();
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, string[]>
	 */
	public function getTagsByGroupIds(array $groupIds): array
	{
		return $this->loadTagsByGroupIds($groupIds);
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	public function getRelationDates(array $groupIds, int $userId): array
	{
		return $this->loadRelationDates($groupIds, $userId);
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	public function getViewDates(array $groupIds, int $userId): array
	{
		return $this->loadViewDates($groupIds, $userId);
	}

	/**
	 * @return int[]
	 */
	public function getGroupIdsByTag(string $tag): array
	{
		$tag = trim($tag);
		if ($tag === '')
		{
			return [];
		}

		$rows = WorkgroupTagTable::query()
			->setSelect(['GROUP_ID'])
			->whereLike('NAME', $tag . '%')
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$groupId = (int)($row['GROUP_ID'] ?? 0);
			if ($groupId > 0)
			{
				$result[$groupId] = true;
			}
		}

		return array_keys($result);
	}

	public function getExtranetGroupIds(): array
	{
		$extranetSiteId = $this->extranetUserService->getExtranetSiteId();
		if ($extranetSiteId === '')
		{
			return [];
		}

		$rows = WorkgroupSiteTable::getList([
			'filter' => ['=SITE_ID' => $extranetSiteId],
			'select' => ['GROUP_ID'],
		])->fetchAll();

		return array_map(
			static fn(array $row): int => (int)$row['GROUP_ID'],
			$rows,
		);
	}

	protected function applyTypeFilter(Query $query): void
	{
		// Legacy grid treats scrum as project with scrum master, not by TYPE field.
		$query
			->where('PROJECT', 'Y')
			->where('SCRUM_MASTER_ID', '>', 0)
		;
	}

	protected function buildPublicVisibilityFilter(): ConditionTree
	{
		// Legacy scrum visibility depends only on VISIBLE, so closed scrum stays in the grid for non-members.
		return Query::filter()
			->where('VISIBLE', 'Y')
		;
	}

	protected function getExtranetUserService(): ExtranetUserService
	{
		return $this->extranetUserService;
	}
}
