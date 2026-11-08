<?php

namespace Bitrix\BIConnector\Public\Provider\ShareRegistry;

use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareBanTable;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Internal\Services\Share\ShareStatusResolver;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Public links of the whole portal for the registry. Statuses are computed on the fly,
 * working links go first, then the most recently opened ones.
 */
class ShareRegistryProvider
{
	private readonly ShareStatusResolver $statusResolver;

	public function __construct(?ShareStatusResolver $statusResolver = null)
	{
		$this->statusResolver = $statusResolver ?? new ShareStatusResolver();
	}

	/**
	 * @return array<array{
	 *     id: int,
	 *     dashboardId: int,
	 *     dashboardTitle: string,
	 *     dashboardType: string,
	 *     authorId: int,
	 *     status: string,
	 *     dateExpire: DateTime,
	 *     dateCreate: ?DateTime,
	 *     dateLastOpened: ?DateTime,
	 *     disabledById: ?int,
	 *     dateDisabled: ?DateTime,
	 *     externalFilterValues: ?string,
	 *     urlParameterValues: ?string,
	 *     isDashboardBanned: bool,
	 * }>
	 */
	public function getList(ShareRegistryFilter $filter, ?Pager $pager = null): array
	{
		$now = new DateTime();
		$condition = $this->buildCondition($filter, $now);
		if ($condition === null)
		{
			return [];
		}

		$query = $this->createQuery()
			->setSelect([
				'ID',
				'DASHBOARD_ID',
				'DASHBOARD_TITLE' => 'DASHBOARD.TITLE',
				'DASHBOARD_TYPE' => 'DASHBOARD.TYPE',
				'CREATED_BY_ID',
				'ACTIVE',
				'DATE_EXPIRE',
				'DATE_CREATE',
				'DATE_MODIFY',
				'DATE_LAST_OPENED',
				'DISABLED_BY_ID',
				'DATE_DISABLED',
				'EXTERNAL_FILTER_VALUES',
				'URL_PARAMETER_VALUES',
				'BAN_ID' => 'BAN.ID',
			])
			->where($condition)
			->registerRuntimeField(new ExpressionField(
				'IS_WORKING',
				"CASE WHEN %s = 'Y' AND %s >= " . $this->toSqlDateTime($now) . ' THEN 1 ELSE 0 END',
				['ACTIVE', 'DATE_EXPIRE'],
			))
			->registerRuntimeField(new ExpressionField(
				'IS_NEVER_OPENED',
				'CASE WHEN %s IS NULL THEN 1 ELSE 0 END',
				['DATE_LAST_OPENED'],
			))
			->setOrder([
				'IS_WORKING' => 'DESC',
				'IS_NEVER_OPENED' => 'ASC',
				'DATE_LAST_OPENED' => 'DESC',
				'ID' => 'DESC',
			])
		;

		if ($pager)
		{
			$query->setLimit($pager->getLimit())->setOffset($pager->getOffset());
		}

		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[] = $this->buildItem($row);
		}

		return $items;
	}

	public function getCount(ShareRegistryFilter $filter): int
	{
		$condition = $this->buildCondition($filter, new DateTime());
		if ($condition === null)
		{
			return 0;
		}

		return (int)$this->createQuery()->where($condition)->queryCountTotal();
	}

	private function createQuery(): Query
	{
		return SupersetDashboardShareTable::query()
			->registerRuntimeField(new Reference(
				'AUTHOR',
				UserTable::class,
				Join::on('this.CREATED_BY_ID', 'ref.ID'),
				['join_type' => Join::TYPE_LEFT],
			))
			->registerRuntimeField(new Reference(
				'BAN',
				SupersetDashboardShareBanTable::class,
				Join::on('this.DASHBOARD_ID', 'ref.DASHBOARD_ID'),
				['join_type' => Join::TYPE_LEFT],
			))
		;
	}

	/**
	 * @return ConditionTree|null Null when no link can match.
	 */
	private function buildCondition(ShareRegistryFilter $filter, DateTime $now): ?ConditionTree
	{
		$condition = $this->buildBaseCondition($filter);

		if ($filter->notOpenedDays !== null)
		{
			$threshold = (clone $now)->add('-' . max(0, $filter->notOpenedDays) . ' days');
			$condition->where(
				Query::filter()
					->logic(ConditionTree::LOGIC_OR)
					->where('DATE_LAST_OPENED', '<', $threshold)
					->where(
						Query::filter()
							->whereNull('DATE_LAST_OPENED')
							->where('DATE_CREATE', '<', $threshold)
					)
			);
		}

		if ($filter->onlyBanned)
		{
			$condition->whereNotNull('BAN.ID');
		}

		if (!empty($filter->statuses))
		{
			$statusCondition = $this->buildStatusCondition($filter, $now);
			if ($statusCondition === null)
			{
				return null;
			}

			$condition->where($statusCondition);
		}

		return $condition;
	}

	private function buildBaseCondition(ShareRegistryFilter $filter): ConditionTree
	{
		$condition = Query::filter();

		if (!empty($filter->authorIds))
		{
			$condition->whereIn('CREATED_BY_ID', $filter->authorIds);
		}

		if (!empty($filter->dashboardIds))
		{
			$condition->whereIn('DASHBOARD_ID', $filter->dashboardIds);
		}

		if (!empty($filter->disabledByIds))
		{
			// A link switched on again keeps its last disabler, but the registry shows no one for it.
			$condition
				->where('ACTIVE', 'N')
				->whereIn('DISABLED_BY_ID', $filter->disabledByIds)
			;
		}

		foreach ($filter->searchWords as $word)
		{
			$pattern = '%' . $this->escapeLike($word) . '%';
			$condition->where(
				Query::filter()
					->logic(ConditionTree::LOGIC_OR)
					->whereLike('DASHBOARD.TITLE', $pattern)
					->whereLike('AUTHOR.NAME', $pattern)
					->whereLike('AUTHOR.LAST_NAME', $pattern)
					// The name cell falls back to the login for authors without a name.
					->whereLike('AUTHOR.LOGIN', $pattern)
			);
		}

		return $condition;
	}

	private function escapeLike(string $value): string
	{
		return addcslashes($value, '\\%_');
	}

	private function buildStatusCondition(ShareRegistryFilter $filter, DateTime $now): ?ConditionTree
	{
		$statuses = array_flip($filter->statuses);
		$variants = [];

		if (isset($statuses[ShareStatusResolver::STATUS_DISABLED]))
		{
			$variants[] = Query::filter()->where('ACTIVE', 'N');
		}

		if (isset($statuses[ShareStatusResolver::STATUS_EXPIRED]))
		{
			$variants[] = Query::filter()
				->where('ACTIVE', 'Y')
				->where('DATE_EXPIRE', '<', $now)
			;
		}

		$wantsActive = isset($statuses[ShareStatusResolver::STATUS_ACTIVE]);
		$wantsNoRight = isset($statuses[ShareStatusResolver::STATUS_NO_RIGHT]);
		if ($wantsActive || $wantsNoRight)
		{
			$authorsWithoutRight = $this->getWorkingAuthorsWithoutRight($filter, $now);

			if ($wantsActive)
			{
				$active = $this->getWorkingCondition($now);
				if (!empty($authorsWithoutRight))
				{
					$active->whereNotIn('CREATED_BY_ID', $authorsWithoutRight);
				}
				$variants[] = $active;
			}

			if ($wantsNoRight && !empty($authorsWithoutRight))
			{
				$variants[] = $this->getWorkingCondition($now)->whereIn('CREATED_BY_ID', $authorsWithoutRight);
			}
		}

		if (empty($variants))
		{
			return null;
		}

		$condition = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach ($variants as $variant)
		{
			$condition->where($variant);
		}

		return $condition;
	}

	private function getWorkingCondition(DateTime $now): ConditionTree
	{
		return Query::filter()
			->where('ACTIVE', 'Y')
			->where('DATE_EXPIRE', '>=', $now)
		;
	}

	/**
	 * @return int[]
	 */
	private function getWorkingAuthorsWithoutRight(ShareRegistryFilter $filter, DateTime $now): array
	{
		$rows = $this->createQuery()
			->setSelect(['CREATED_BY_ID'])
			->setDistinct()
			->where($this->buildBaseCondition($filter))
			->where($this->getWorkingCondition($now))
			->fetchAll()
		;

		$authorIds = [];
		foreach ($rows as $row)
		{
			$authorId = (int)$row['CREATED_BY_ID'];
			if (!$this->statusResolver->canAuthorShare($authorId))
			{
				$authorIds[] = $authorId;
			}
		}

		return $authorIds;
	}

	private function buildItem(array $row): array
	{
		$isActive = $row['ACTIVE'] === 'Y';
		$authorId = (int)$row['CREATED_BY_ID'];
		$disabledById = $row['DISABLED_BY_ID'] === null ? null : (int)$row['DISABLED_BY_ID'];
		$isDashboardBanned = $row['BAN_ID'] !== null;

		return [
			'id' => (int)$row['ID'],
			'dashboardId' => (int)$row['DASHBOARD_ID'],
			'dashboardTitle' => (string)$row['DASHBOARD_TITLE'],
			'dashboardType' => (string)$row['DASHBOARD_TYPE'],
			'authorId' => $authorId,
			'status' => $this->statusResolver->resolve($isActive, $authorId, $row['DATE_EXPIRE']),
			'dateExpire' => $row['DATE_EXPIRE'],
			'dateCreate' => $row['DATE_CREATE'],
			'dateLastOpened' => $row['DATE_LAST_OPENED'],
			'disabledById' => $isActive ? null : $disabledById,
			'dateDisabled' => $isActive ? null : ($row['DATE_DISABLED'] ?? $row['DATE_MODIFY']),
			'externalFilterValues' => $row['EXTERNAL_FILTER_VALUES'],
			'urlParameterValues' => $row['URL_PARAMETER_VALUES'],
			'isDashboardBanned' => $isDashboardBanned,
		];
	}

	private function toSqlDateTime(DateTime $value): string
	{
		return Application::getConnection()->getSqlHelper()->convertToDbDateTime($value);
	}
}
