<?php

declare(strict_types=1);

namespace Bitrix\Sale\Internal\Repository;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;
use Bitrix\Main\UserUtils;
use Bitrix\Sale\Internal\Repository\Mapper\BuyerStatisticMapper;
use Bitrix\Sale\Internals\BuyerStatisticTable;
use Bitrix\Sale\Public\Dto\BuyerStatisticCollection;
use Bitrix\Sale\Public\Provider\Params\BuyerFilter;
use Bitrix\Sale\Public\Provider\Params\BuyerSort;

/**
 * Rows of the buyers list: the statistic of a buyer on one site in one currency, with the buyer record.
 *
 * The list has two sources. Without a group of the shop buyers it is the statistic table itself, row
 * for row, so every column it sorts and filters by is a plain column of the table served by an index
 * of its own — no aggregate, no grouping — and a buyer without statistic is not listed. With the
 * group it is the members of the group, each with every row of the statistic, and a member who has
 * paid for nothing yet is one row of zeros: a buyer added by hand is listed before the first order.
 * The members come first there, so the order of the list is sorted from them and not read off an
 * index of the statistic.
 */
final class BuyerRepository
{
	/** Reference to the buyer record from the statistic. */
	private const BUYER_REFERENCE_NAME = 'BUYER';
	/** Reference to the statistic from a member of the group. */
	private const STATISTIC_REFERENCE_NAME = 'STATISTIC';
	private const SHOP_GROUP_REFERENCE_NAME = 'SHOP_GROUP';
	/** Prefix of the numbers of the statistic a member without it reads as zeros. */
	private const METRIC_PREFIX = 'STAT_';
	/** Whether a member has the last order date: the key the order by that date starts with. */
	private const HAS_LAST_ORDER_DATE_NAME = 'STAT_HAS_LAST_ORDER_DATE';
	private const COUNT_NAME = 'ROW_COUNT';

	/** Columns of the statistic row every row of the list is read with. */
	private const STATISTIC_SELECT = [
		'ID',
		'USER_ID',
		'LID',
		'CURRENCY',
		'SUM_PAID',
		'COUNT_FULL_PAID_ORDER',
		'COUNT_PART_PAID_ORDER',
		'LAST_ORDER_DATE',
	];

	/** Numbers of the statistic row: zero, and not empty, for a member who has none. */
	private const METRICS = [
		'SUM_PAID',
		'COUNT_FULL_PAID_ORDER',
		'COUNT_PART_PAID_ORDER',
	];

	/** Columns of the buyer record every row of the list is read with. */
	private const USER_SELECT = [
		'LOGIN',
		'NAME',
		'LAST_NAME',
		'SECOND_NAME',
		'EMAIL',
		'PERSONAL_PHONE',
		'LAST_LOGIN',
		'DATE_REGISTER',
		'EXTERNAL_AUTH_ID',
	];

	/**
	 * @param PagerInterface|null $pager page of the list, null for the whole of it
	 * @param int $excludedUserId buyer the list never shows, such as the anonymous one; 0 for none
	 * @param int $shopGroupId group of the shop buyers the list is made of; 0 for the statistic alone
	 */
	public function getList(
		BuyerFilter $filter,
		BuyerSort $sort,
		?PagerInterface $pager = null,
		int $excludedUserId = 0,
		int $shopGroupId = 0,
	): BuyerStatisticCollection
	{
		$shopGroupId = $this->resolveShopGroupId($filter, $shopGroupId);
		$query = $this->createQuery($filter, $excludedUserId, true, $shopGroupId)
			->setSelect($this->createSelect($shopGroupId > 0))
			->setOrder($this->resolveOrder($sort, $shopGroupId > 0))
		;
		if ($pager !== null)
		{
			$query
				->setLimit($pager->getLimit())
				->setOffset($pager->getOffset())
			;
		}

		$rows = new BuyerStatisticCollection();
		foreach ($query->exec()->fetchAll() as $row)
		{
			$rows->add(BuyerStatisticMapper::convertFromRow($row));
		}

		return $rows;
	}

	/**
	 * Number of rows getList() returns for the same conditions.
	 *
	 * The buyer record is joined to the statistic by LEFT JOIN on its primary key, so it neither adds
	 * rows nor takes them away: the count over the statistic leaves it out unless a condition needs
	 * it, and stays an index count.
	 */
	public function getCount(BuyerFilter $filter, int $excludedUserId = 0, int $shopGroupId = 0): int
	{
		$shopGroupId = $this->resolveShopGroupId($filter, $shopGroupId);
		$query = $this->createQuery($filter, $excludedUserId, $filter->usesBuyerRecord(), $shopGroupId);
		$query->registerRuntimeField('', new ExpressionField(
			self::COUNT_NAME,
			'COUNT(1)',
			[],
			['data_type' => 'integer']
		));

		$row = $query->setSelect([self::COUNT_NAME])->exec()->fetch();

		return (int)($row[self::COUNT_NAME] ?? 0);
	}

	/**
	 * Group of the shop buyers the list has to be made of, 0 when the statistic alone gives the rows.
	 *
	 * The group is there for the buyers who have paid for nothing yet, and the list built from it is
	 * sorted by hand from all of its members. A filter no such buyer can pass leaves only the rows of
	 * the statistic, and those are read off its indexes without the group.
	 */
	public function resolveShopGroupId(BuyerFilter $filter, int $shopGroupId): int
	{
		return $shopGroupId > 0 && !$filter->excludesBuyersWithoutStatistic() ? $shopGroupId : 0;
	}

	/**
	 * Query of the list with its filter and runtime fields, but no select and no order.
	 *
	 * @param bool $withBuyer whether the buyer record is joined to the statistic; the members of the
	 *   group are the buyer records themselves
	 */
	public function createQuery(BuyerFilter $filter, int $excludedUserId, bool $withBuyer, int $shopGroupId = 0): Query
	{
		$ofShop = $shopGroupId > 0;
		$query = $ofShop ? $this->createShopQuery($shopGroupId) : $this->createStatisticQuery($withBuyer);

		// Conditions of the filter come first: the predicate the list is restricted by must stay the
		// one set here, whatever operator prefix arrives with a filter field.
		$baseFilter = $excludedUserId > 0 ? ['!=' . $this->resolveUserIdField($ofShop) => $excludedUserId] : [];

		$query->setFilter(array_merge(
			$this->convertFilter($filter, $ofShop),
			$this->convertUserFilter($filter, $ofShop),
			$this->convertSearch($filter, $ofShop),
			$baseFilter
		));

		$groupIds = $this->pickGroupIds($filter);
		if ($groupIds !== [])
		{
			$query->whereExists($this->createGroupSubQuery($query, $groupIds, $ofShop));
		}

		return $query;
	}

	/**
	 * Order of the list for the sorting asked for.
	 *
	 * Rows with the same value are the normal case, so the id of the row closes the order: without a
	 * stable second key the same row shows up on two pages of the offset paging while another one is
	 * skipped. It goes in the direction of the first key, so over the statistic the index of that
	 * column — which holds the primary key after the column — serves the order as it stands. Among
	 * the members of the group a row is a buyer and one of the rows of the statistic, possibly none.
	 *
	 * @return array<string, string>
	 */
	public function resolveOrder(BuyerSort $sort, bool $ofShop = false): array
	{
		$column = $sort->getField();
		$direction = $sort->getDirection();

		if ($sort->usesBuyerRecord())
		{
			$field = $this->resolveUserField($column, $ofShop);
		}
		elseif ($column === BuyerFilter::BUYER_ID)
		{
			$field = $this->resolveUserIdField($ofShop);
		}
		else
		{
			$field = $this->resolveStatisticField($column, $ofShop);
		}

		if (!$ofShop)
		{
			return [$field => $direction, 'ID' => $direction];
		}

		$order = [
			$field => $direction,
			'ID' => $direction,
			self::STATISTIC_REFERENCE_NAME . '.ID' => $direction,
		];

		// A member without orders has no date, and the databases disagree on where NULL goes:
		// MySQL takes it for the least value, PostgreSQL for the greatest. The flag makes it the
		// least on both, so the list opens with the latest orders.
		if ($column === 'LAST_ORDER_DATE')
		{
			$order = [self::HAS_LAST_ORDER_DATE_NAME => $direction] + $order;
		}

		return $order;
	}

	/**
	 * Conditions on the buyer id and the columns of the statistic row.
	 * Operator prefixes of the admin filter are preserved.
	 */
	public function convertFilter(BuyerFilter $filter, bool $ofShop = false): array
	{
		$result = [];

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition['field'] === BuyerFilter::BUYER_ID)
			{
				$result[$condition['operator'] . $this->resolveUserIdField($ofShop)] = $condition['value'];
			}
			elseif (in_array($condition['field'], BuyerFilter::STATISTIC_FIELDS, true))
			{
				$field = $this->resolveStatisticField($condition['field'], $ofShop);
				$result[$condition['operator'] . $field] = $condition['value'];
			}
		}

		return $result;
	}

	/**
	 * Conditions on the buyer record.
	 *
	 * The operator prefix arrives in front of the field, so the date ranges of the filter come as
	 * `>=USER.LAST_LOGIN` and `<=USER.DATE_REGISTER` and are kept as `>=BUYER.LAST_LOGIN`.
	 *
	 * Two conditions on a string are the exception, the substring `%` and the bare value the filter
	 * sends for the login and the e-mail: the ORM wraps the column of both in UPPER(), and the
	 * optimizer, which cannot tell how many buyers such a condition leaves, walks the index of the
	 * order over the whole statistic looking for them — tens of seconds on a million rows when the
	 * value is rare. A plain LIKE and a plain comparison are estimated and read off the buyers first,
	 * the latter by the index of the column. The collations of MySQL compare without case anyway.
	 */
	public function convertUserFilter(BuyerFilter $filter, bool $ofShop = false): array
	{
		$result = [];

		foreach ($filter->getConditions() as $condition)
		{
			if (!str_starts_with($condition['field'], BuyerFilter::USER_PREFIX))
			{
				continue;
			}

			$field = $this->resolveUserField(substr($condition['field'], strlen(BuyerFilter::USER_PREFIX)), $ofShop);
			$operator = $condition['operator'];
			$value = $condition['value'];

			if ($operator === '%' && is_string($value))
			{
				// `%=` takes the pattern as given, so the wildcards typed in by the operator are
				// escaped to stay plain characters.
				$operator = '%=';
				$value = '%' . addcslashes($value, '\\%_') . '%';
			}
			elseif ($operator === '' && is_string($value))
			{
				$operator = '=';
			}

			$result[$operator . $field] = $value;
		}

		return $result;
	}

	private function createStatisticQuery(bool $withBuyer): Query
	{
		$query = new Query(BuyerStatisticTable::getEntity());

		if ($withBuyer)
		{
			// LEFT and not the INNER of the USER reference of the table: a row whose buyer record is
			// gone is still a row of the table, and the count, which does not join, sees it.
			$query->registerRuntimeField('', new ReferenceField(
				self::BUYER_REFERENCE_NAME,
				UserTable::getEntity(),
				['=this.USER_ID' => 'ref.ID'],
				['join_type' => 'LEFT']
			));
		}

		return $query;
	}

	/**
	 * Members of the group, each with every row of the statistic and with none when there is none.
	 */
	private function createShopQuery(int $shopGroupId): Query
	{
		$query = new Query(UserTable::getEntity());

		// The pair of the buyer and the group is the primary key of the membership: one row a buyer.
		$query->registerRuntimeField('', new ReferenceField(
			self::SHOP_GROUP_REFERENCE_NAME,
			UserGroupTable::getEntity(),
			[
				'=this.ID' => 'ref.USER_ID',
				'=ref.GROUP_ID' => new SqlExpression('?i', $shopGroupId),
			],
			['join_type' => 'INNER']
		));
		$query->registerRuntimeField('', new ReferenceField(
			self::STATISTIC_REFERENCE_NAME,
			BuyerStatisticTable::getEntity(),
			['=this.ID' => 'ref.USER_ID'],
			['join_type' => 'LEFT']
		));

		// A member without statistic shows zeros, so the filter and the order take them for zeros
		// too: «no more than one order» finds the buyer who has none.
		foreach (self::METRICS as $metric)
		{
			$query->registerRuntimeField('', new ExpressionField(
				self::METRIC_PREFIX . $metric,
				'COALESCE(%s, 0)',
				[self::STATISTIC_REFERENCE_NAME . '.' . $metric]
			));
		}

		$query->registerRuntimeField('', new ExpressionField(
			self::HAS_LAST_ORDER_DATE_NAME,
			'CASE WHEN %s IS NULL THEN 0 ELSE 1 END',
			[self::STATISTIC_REFERENCE_NAME . '.LAST_ORDER_DATE'],
			['data_type' => 'integer']
		));

		return $query;
	}

	/**
	 * @return array<int|string, string>
	 */
	private function createSelect(bool $ofShop): array
	{
		if (!$ofShop)
		{
			$select = self::STATISTIC_SELECT;
			foreach (self::USER_SELECT as $field)
			{
				$select[$field] = self::BUYER_REFERENCE_NAME . '.' . $field;
			}

			return $select;
		}

		$select = ['USER_ID' => 'ID'];
		foreach (self::STATISTIC_SELECT as $field)
		{
			if ($field !== 'USER_ID')
			{
				$column = $field === 'ID' ? self::STATISTIC_REFERENCE_NAME . '.ID' : $this->resolveStatisticField($field, true);
				$select[BuyerStatisticMapper::STATISTIC_PREFIX . $field] = $column;
			}
		}

		return array_merge($select, self::USER_SELECT);
	}

	private function resolveUserIdField(bool $ofShop): string
	{
		return $ofShop ? 'ID' : BuyerFilter::BUYER_ID;
	}

	private function resolveUserField(string $field, bool $ofShop): string
	{
		return $ofShop ? $field : self::BUYER_REFERENCE_NAME . '.' . $field;
	}

	private function resolveStatisticField(string $field, bool $ofShop): string
	{
		if (!$ofShop)
		{
			return $field;
		}

		return in_array($field, self::METRICS, true)
			? self::METRIC_PREFIX . $field
			: self::STATISTIC_REFERENCE_NAME . '.' . $field
		;
	}

	/**
	 * Quick search of the list, matched against the buyer record of the row.
	 */
	private function convertSearch(BuyerFilter $filter, bool $ofShop): array
	{
		$search = $filter->getSearch();
		if ($search === '')
		{
			return [];
		}

		$searchFilter = UserUtils::getAdminSearchFilter(['FIND' => $search]);

		return $ofShop ? $searchFilter : $this->prefixUserFields($searchFilter);
	}

	/**
	 * Moves the fields of a buyers filter, nested groups included, behind the reference to the buyer.
	 */
	private function prefixUserFields(array $filter): array
	{
		$result = [];

		foreach ($filter as $key => $value)
		{
			if (is_array($value))
			{
				$result[$key] = $this->prefixUserFields($value);

				continue;
			}

			if (is_string($key) && $key !== 'LOGIC' && preg_match('/^(\W*)(.+)$/s', $key, $matches))
			{
				$key = $matches[1] . self::BUYER_REFERENCE_NAME . '.' . $matches[2];
			}

			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * Narrows the list down to the rows of the buyers who belong to one of the groups.
	 *
	 * EXISTS and not a join: a join repeats the row for every group of the choice the buyer is in,
	 * and a join on the grouped subquery of the buyers of a group is materialized whole — the whole
	 * group of the shop buyers, sorted, for a page of twenty. EXISTS is checked row by row while the
	 * index of the order is walked, and a small group is read first by the optimizer itself.
	 *
	 * @param int[] $groupIds
	 */
	private function createGroupSubQuery(Query $query, array $groupIds, bool $ofShop): Query
	{
		return UserGroupTable::query()
			->registerRuntimeField('', new ExpressionField('ONE', '1'))
			->setSelect(['ONE'])
			->whereIn('GROUP_ID', $groupIds)
			->where('USER_ID', new SqlExpression('?#.?#', $query->getInitAlias(), $this->resolveUserIdField($ofShop)))
		;
	}

	/**
	 * Groups of the group condition of the filter, empty when it holds none.
	 *
	 * @return int[]
	 */
	private function pickGroupIds(BuyerFilter $filter): array
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition['field'] === BuyerFilter::GROUP_FIELD)
			{
				$groupIds = array_map('intval', (array)$condition['value']);

				return array_values(array_unique(array_filter($groupIds, static fn(int $id): bool => $id > 0)));
			}
		}

		return [];
	}
}
