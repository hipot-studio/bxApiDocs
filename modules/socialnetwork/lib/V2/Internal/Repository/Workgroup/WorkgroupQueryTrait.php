<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Workgroup;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;
use Bitrix\Tasks\Internals\Task\ProjectLastActivityTable;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\WorkgroupPinTable;
use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Socialnetwork\WorkgroupTagTable;
use Bitrix\Socialnetwork\WorkgroupViewTable;

trait WorkgroupQueryTrait
{
	protected function createListQuery(
		array $select,
		?ConditionTree $filter,
		array $sort,
		?int $offset,
		?int $limit,
		?int $currentUserId,
		?int $contextUserId,
		bool $isAdmin,
		?int $pinUserId = null,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::Common,
	): Query
	{
		$query = WorkgroupTable::query();

		$this->applyTypeFilter($query);
		$this->applySiteFilter($query);
		$this->applyActivityDateFieldIfNeeded($query, $select, $sort);
		$this->applyRelationDateFieldIfNeeded($query, $select, $sort, $contextUserId);
		$this->applyViewDateFieldIfNeeded($query, $select, $sort, $currentUserId);
		$this->applyPinOrderIfNeeded($query, $sort, $pinUserId, $pinMode);

		if ($select !== [])
		{
			$query->setSelect($select);
		}

		if ($filter !== null)
		{
			$query->where($filter);
		}

		if ($currentUserId !== null)
		{
			$this->applyVisibilityFilter($query, $currentUserId, $isAdmin);
		}

		if ($sort !== [])
		{
			$query->setOrder($sort);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		return $query;
	}

	private function applyPinOrderIfNeeded(
		Query $query,
		array &$sort,
		?int $pinUserId,
		WorkgroupPinMode $pinMode,
	): void
	{
		if (($pinUserId ?? 0) <= 0)
		{
			return;
		}

		$pinJoin = Join::on('this.ID', 'ref.GROUP_ID')
			->where('ref.USER_ID', $pinUserId);

		if ($pinMode === WorkgroupPinMode::Common)
		{
			$pinJoin = $pinJoin->where(
				Query::filter()
					->logic('or')
					->whereNull('ref.CONTEXT')
					->where('ref.CONTEXT', '')
			);
		}
		else
		{
			$pinJoin = $pinJoin->where('ref.CONTEXT', $pinMode->value);
		}

		$query->registerRuntimeField(
			(new Reference(
				name: 'PIN',
				referenceEntity: WorkgroupPinTable::class,
				referenceFilter: $pinJoin,
			))->configureJoinType(Join::TYPE_LEFT),
		);

		$query->registerRuntimeField(
			null,
			new ExpressionField(
				'PINNED_PRIORITY',
				'(CASE WHEN %s IS NULL THEN 0 ELSE 1 END)',
				['PIN.ID'],
			),
		);

		$sort = ['PINNED_PRIORITY' => 'DESC'] + $sort;
	}

	private function applyActivityDateFieldIfNeeded(Query $query, array $select, array $sort): void
	{
		if (
			!in_array('ACTIVITY_DATE', $select, true)
			&& !array_key_exists('ACTIVITY_DATE', $sort)
		)
		{
			return;
		}

		if (!Loader::includeModule('tasks'))
		{
			return;
		}

		$helper = Application::getConnection()->getSqlHelper();

		$query->registerRuntimeField(
			(new Reference(
				name: 'PLA',
				referenceEntity: ProjectLastActivityTable::class,
				referenceFilter: Join::on('this.ID', 'ref.PROJECT_ID'),
			))->configureJoinType(Join::TYPE_LEFT),
		);

		$query->registerRuntimeField(
			null,
			new ExpressionField(
				'ACTIVITY_DATE',
				$helper->getIsNullFunction('%s', '%s'),
				['PLA.ACTIVITY_DATE', 'DATE_UPDATE'],
			),
		);
	}

	private function applyRelationDateFieldIfNeeded(
		Query $query,
		array $select,
		array $sort,
		?int $contextUserId,
	): void
	{
		if (
			!in_array('DATE_RELATION', $select, true)
			&& !array_key_exists('DATE_RELATION', $sort)
		)
		{
			return;
		}

		if (($contextUserId ?? 0) <= 0)
		{
			return;
		}

		$query->registerRuntimeField(
			(new Reference(
				name: 'GRID_RELATION',
				referenceEntity: UserToGroupTable::class,
				referenceFilter: Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $contextUserId),
			))->configureJoinType(Join::TYPE_LEFT),
		);

		$query->registerRuntimeField(
			null,
			new ExpressionField(
				'DATE_RELATION',
				'%s',
				['GRID_RELATION.DATE_UPDATE'],
			),
		);
	}

	private function applyViewDateFieldIfNeeded(
		Query $query,
		array $select,
		array $sort,
		?int $currentUserId,
	): void
	{
		if (
			!in_array('DATE_VIEW', $select, true)
			&& !array_key_exists('DATE_VIEW', $sort)
		)
		{
			return;
		}

		if (($currentUserId ?? 0) <= 0)
		{
			return;
		}

		$query->registerRuntimeField(
			(new Reference(
				name: 'GRID_VIEW',
				referenceEntity: WorkgroupViewTable::class,
				referenceFilter: Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $currentUserId),
			))->configureJoinType(Join::TYPE_LEFT),
		);

		$query->registerRuntimeField(
			null,
			new ExpressionField(
				'DATE_VIEW',
				'%s',
				['GRID_VIEW.DATE_VIEW'],
			),
		);
	}

	protected function createCountQuery(
		?ConditionTree $filter,
		?int $currentUserId,
		bool $isAdmin,
	): Query
	{
		$query = WorkgroupTable::query()
			->setSelect(['ID'])
			->countTotal(true)
		;

		$this->applyTypeFilter($query);
		$this->applySiteFilter($query);

		if ($filter !== null)
		{
			$query->where($filter);
		}

		if ($currentUserId !== null)
		{
			$this->applyVisibilityFilter($query, $currentUserId, $isAdmin);
		}

		return $query;
	}

	abstract protected function applyTypeFilter(Query $query): void;

	abstract protected function getExtranetUserService(): ExtranetUserService;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string[]>
	 */
	protected function loadTagsByGroupIds(array $groupIds): array
	{
		if (empty($groupIds))
		{
			return [];
		}

		$rows = WorkgroupTagTable::getList([
			'filter' => ['=GROUP_ID' => $groupIds],
			'select' => ['GROUP_ID', 'NAME'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$name = $row['NAME'] ?? '';
			if ($name !== '')
			{
				$result[(int)$row['GROUP_ID']][] = $name;
			}
		}

		return $result;
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	protected function loadRelationDates(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$rows = UserToGroupTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupIds,
				'=USER_ID' => $userId,
			],
			'select' => ['GROUP_ID', 'DATE_UPDATE'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$date = $row['DATE_UPDATE'] ?? null;
			if ($date instanceof DateTime)
			{
				$result[(int)$row['GROUP_ID']] = $date->toString();
			}
		}

		return $result;
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	protected function loadViewDates(array $groupIds, int $userId): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$rows = WorkgroupViewTable::getList([
			'filter' => [
				'=GROUP_ID' => $groupIds,
				'=USER_ID' => $userId,
			],
			'select' => ['GROUP_ID', 'DATE_VIEW'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$date = $row['DATE_VIEW'] ?? null;
			if ($date instanceof DateTime)
			{
				$result[(int)$row['GROUP_ID']] = $date->toString();
			}
		}

		return $result;
	}

	private function applySiteFilter(Query $query): void
	{
		$query->registerRuntimeField(
			(new Reference(
				name: 'SITE',
				referenceEntity: WorkgroupSiteTable::class,
				referenceFilter: Join::on('this.ID', 'ref.GROUP_ID'),
			))->configureJoinType(Join::TYPE_INNER),
		);

		$query->where('SITE.SITE_ID', SITE_ID);
	}

	protected function buildPublicVisibilityFilter(): ConditionTree
	{
		return Query::filter()
			->where('VISIBLE', 'Y')
			->where('OPENED', 'Y')
		;
	}

	protected function buildVisibilityFilter(int $currentUserId): ConditionTree
	{
		$filter = Query::filter();

		if ($this->getExtranetUserService()->isExtranet($currentUserId))
		{
			return $filter->where('CURRENT_RELATION.ROLE', '<=', Role::Member->value);
		}

		return $filter
			->logic(ConditionTree::LOGIC_OR)
			->where($this->buildPublicVisibilityFilter())
			->where('CURRENT_RELATION.ROLE', '<=', Role::Member->value)
		;
	}

	protected function applyVisibilityFilter(Query $query, int $currentUserId, bool $isAdmin): void
	{
		if ($currentUserId <= 0)
		{
			$query->where($this->buildPublicVisibilityFilter());

			return;
		}

		if ($isAdmin)
		{
			return;
		}

		$query->registerRuntimeField(
			(new Reference(
				name: 'CURRENT_RELATION',
				referenceEntity: UserToGroupTable::class,
				referenceFilter: Join::on('this.ID', 'ref.GROUP_ID')->where('ref.USER_ID', $currentUserId),
			))->configureJoinType(Join::TYPE_LEFT),
		);

		$query->where($this->buildVisibilityFilter($currentUserId));
	}
}
