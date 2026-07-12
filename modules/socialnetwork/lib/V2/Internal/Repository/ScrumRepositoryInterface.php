<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Socialnetwork\Internals\Group\GroupEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;

interface ScrumRepositoryInterface
{
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
	): GroupEntityCollection;

	public function getCount(
		?ConditionTree $filter = null,
		?int $currentUserId = null,
		bool $isAdmin = false,
	): int;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string[]>
	 */
	public function getTagsByGroupIds(array $groupIds): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	public function getRelationDates(array $groupIds, int $userId): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	public function getViewDates(array $groupIds, int $userId): array;

	/**
	 * @return int[]
	 */
	public function getGroupIdsByTag(string $tag): array;

	/**
	 * @return int[]
	 */
	public function getExtranetGroupIds(): array;

}
