<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\Internals\Group\GroupEntityCollection;
use Bitrix\Socialnetwork\Provider\GroupProvider;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;
use Bitrix\Socialnetwork\V2\Internal\Repository\Workgroup\WorkgroupQueryTrait;

/**
 * Repository for ALL workgroup types (projects, scrums, collabs, groups).
 * No type filter — returns all types.
 * Used by COMMON/USER grid mode.
 */
class WorkgroupRepository
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

	public function isNameExists(string $name, ?int $excludeGroupId = null): bool
	{
		return GroupProvider::getInstance()->isExistingGroup($name, $excludeGroupId ?? 0);
	}

	public function hasLegacyDepartment(int $groupId, int $departmentId): bool
	{
		return in_array($departmentId, $this->getLegacyDepartmentIds($groupId), true);
	}

	/**
	 * Department ids attached to a group the legacy way (the UF_SG_DEPT field), excluding HR node relations
	 *
	 * @return int[]
	 */
	public function getLegacyDepartmentIds(int $groupId): array
	{
		$row =
			WorkgroupTable::query()
				->setSelect(['UF_SG_DEPT'])
				->where('ID', $groupId)
				->exec()
				->fetch()
		;

		$departmentIds = $row['UF_SG_DEPT'] ?? [];
		Collection::normalizeArrayValuesByInt($departmentIds, false);

		return $departmentIds;
	}

	protected function applyTypeFilter(Query $query): void
	{
		// No type filter — all workgroup types included
	}

	protected function getExtranetUserService(): ExtranetUserService
	{
		return $this->extranetUserService;
	}
}
