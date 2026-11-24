<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule;

use Bitrix\BIConnector\Internal\Grid\RowRule\Column\Provider\RowRuleDataProvider;
use Bitrix\BIConnector\Internal\Grid\RowRule\Filter\RowRuleFilterProvider;

/**
 * Turns the state of the grid into the `list` request of the rule provider.
 */
final class RowRuleListRequest
{
	private const ORDER_FIELDS = [
		RowRuleDataProvider::SORT_TABLE => 'tableCode',
		RowRuleDataProvider::SORT_DATE_CREATE => 'dateCreate',
		RowRuleDataProvider::SORT_DATE_MODIFY => 'dateModify',
	];

	/**
	 * @param array $filter Value of {@see RowRuleFilterProvider::prepareFilterValue()}.
	 * @param array $order Order of the grid: sort key => direction; only the first known key counts.
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return array{filter: array, order: array{field: string, direction: string}, offset: int, limit: int}
	 */
	public static function build(array $filter, array $order, int $offset, int $limit): array
	{
		$request = [
			'filter' => [],
			'order' => self::buildOrder($order),
			'offset' => max(0, $offset),
			'limit' => max(1, $limit),
		];

		if (!empty($filter[RowRuleFilterProvider::FIELD_TABLE]))
		{
			$request['filter']['tableCode'] = array_values((array)$filter[RowRuleFilterProvider::FIELD_TABLE]);
		}

		if (!empty($filter[RowRuleFilterProvider::FIELD_AUTHOR]))
		{
			$request['filter']['createdById'] = array_values((array)$filter[RowRuleFilterProvider::FIELD_AUTHOR]);
		}

		if (!empty($filter[RowRuleFilterProvider::FIELD_EDITOR]))
		{
			$request['filter']['updatedById'] = array_values((array)$filter[RowRuleFilterProvider::FIELD_EDITOR]);
		}

		$isUsed = $filter[RowRuleFilterProvider::FIELD_IS_USED] ?? null;
		if ($isUsed === RowRuleFilterProvider::VALUE_YES || $isUsed === RowRuleFilterProvider::VALUE_NO)
		{
			$request['filter']['isUsed'] = $isUsed === RowRuleFilterProvider::VALUE_YES;
		}

		return $request;
	}

	/**
	 * @return array{field: string, direction: string}
	 */
	private static function buildOrder(array $order): array
	{
		foreach ($order as $key => $direction)
		{
			$field = self::ORDER_FIELDS[$key] ?? null;
			if ($field === null)
			{
				continue;
			}

			return [
				'field' => $field,
				'direction' => strtolower((string)$direction) === 'asc' ? 'asc' : 'desc',
			];
		}

		return ['field' => 'dateModify', 'direction' => 'desc'];
	}
}
