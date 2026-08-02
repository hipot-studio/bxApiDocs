<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider\Params\Resource;

use Bitrix\Booking\Internals\Model\ResourceSettingsTable;
use Bitrix\Booking\Internals\Model\ResourceSkuTable;
use Bitrix\Booking\Internals\Model\ResourceSkuYandexTable;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Booking\Provider\Params\Filter;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

class ResourceFilter extends Filter
{
	private const MAX_SHORT_SLOT_SIZE = 12 * Time::MINUTES_IN_HOUR;

	private array $filter;
	private string $initAlias;

	public function __construct(array $filter = [])
	{
		$this->filter = $filter;
	}

	public function prepareQuery(Query $query): void
	{
		$this->initAlias = $query->getInitAlias();

		parent::prepareQuery($query);
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		$resourceIds = [];
		if (isset($this->filter['ID']))
		{
			$resourceIds = is_array($this->filter['ID'])
				? array_map('intval', $this->filter['ID'])
				: [(int)$this->filter['ID']];
		}

		if ($this->filter['SHORT_SLOTS_ONLY'] ?? false)
		{
			if (!empty($resourceIds))
			{
				$longSlotResourceIds = $this->getLongSlotResourceIds($resourceIds);
				$resourceIds = array_diff($resourceIds, $longSlotResourceIds);

				$result->whereIn('ID', empty($resourceIds) ? [0] : array_map('intval', $resourceIds));
			}
			else
			{
				$longSlotResourceIds = $this->getLongSlotResourceIds([]);
				if (!empty($longSlotResourceIds))
				{
					$result->whereNotIn('ID', array_map('intval', $longSlotResourceIds));
				}
			}
		}
		elseif (!empty($resourceIds))
		{
			$result->whereIn('ID', array_map('intval', $resourceIds));
		}

		if (isset($this->filter['EXTERNAL_ID']))
		{
			if (is_array($this->filter['EXTERNAL_ID']))
			{
				$result->whereIn('EXTERNAL_ID', array_map('intval', $this->filter['EXTERNAL_ID']));
			}
			else
			{
				$result->where('EXTERNAL_ID', '=', (int)$this->filter['EXTERNAL_ID']);
			}
		}

		if (isset($this->filter['IS_MAIN']))
		{
			$result->where('IS_MAIN', '=', (bool)$this->filter['IS_MAIN']);
		}

		$includeDeleted = (
			isset($this->filter['INCLUDE_DELETED'])
			&& $this->filter['INCLUDE_DELETED'] === true
		);

		if (!$includeDeleted)
		{
			$result->where('DATA.IS_DELETED', '=', false);
		}

		if (isset($this->filter['TYPE_ID']))
		{
			if (is_array($this->filter['TYPE_ID']))
			{
				$result->whereIn('TYPE_ID', array_map('intval', $this->filter['TYPE_ID']));
			}
			else
			{
				$result->where('TYPE_ID', '=', (int)$this->filter['TYPE_ID']);
			}
		}

		if (isset($this->filter['NAME']))
		{
			$result->where('DATA.NAME', '=', (string)$this->filter['NAME']);
		}

		if (isset($this->filter['SEARCH_QUERY']))
		{
			$result->whereLike('DATA.NAME', '%' . $this->filter['SEARCH_QUERY'] . '%');
		}

		if (isset($this->filter['DESCRIPTION']))
		{
			$result->where('DATA.DESCRIPTION', '=', (string)$this->filter['DESCRIPTION']);
		}

		if (isset($this->filter['CREATED_BY']))
		{
			if (is_array($this->filter['CREATED_BY']))
			{
				$result->whereIn('DATA.CREATED_BY', array_map('intval', $this->filter['CREATED_BY']));
			}
			else
			{
				$result->where('DATA.CREATED_BY', '=', (int)$this->filter['CREATED_BY']);
			}
		}

		if (isset($this->filter['SENDER_CODE']))
		{
			if (is_array($this->filter['SENDER_CODE']))
			{
				$result->whereIn(
					'NOTIFICATION_SETTINGS.SENDER_CODE',
					array_map('strval', $this->filter['SENDER_CODE']),
				);
			}
			else
			{
				$result->where('NOTIFICATION_SETTINGS.SENDER_CODE', '=', (string)$this->filter['SENDER_CODE']);
			}
		}

		$this->applyServicesFilters($result);

		return $result;
	}

	private function applyServicesFilters(ConditionTree $result): void
	{
		$this->applyWithSkusFilter($result, 'WITH_SKUS', ResourceSkuTable::class);
		$this->applyHasSkusFilter($result, 'HAS_SKUS', ResourceSkuTable::class);

		$this->applyWithSkusFilter($result, 'WITH_SKUS_YANDEX', ResourceSkuYandexTable::class);
		$this->applyHasSkusFilter($result, 'HAS_SKUS_YANDEX', ResourceSkuYandexTable::class);
	}

	protected function getLongSlotResourceIds(array $resourceIds): array
	{
		$query = ResourceSettingsTable::query()
			->setSelect(['RESOURCE_ID'])
			->setGroup(['RESOURCE_ID'])
			->where('SLOT_SIZE', '>', self::MAX_SHORT_SLOT_SIZE)
		;

		if (!empty($resourceIds))
		{
			$query->whereIn('RESOURCE_ID', $resourceIds);
		}

		return array_column($query->exec()->fetchAll(), 'RESOURCE_ID');
	}

	/**
	 * @param ConditionTree $result
	 * @param string $filterKey
	 * @param class-string<DataManager> $tableClass
	 * @return void
	 */
	private function applyWithSkusFilter(ConditionTree $result, string $filterKey, string $tableClass): void
	{
		if (!isset($this->filter[$filterKey]))
		{
			return;
		}

		$has = (bool)$this->filter[$filterKey];

		$filter = Query::filter()->whereExists(
			new SqlExpression("
				SELECT 1
				FROM " . $tableClass::getTableName() . "
				WHERE
					RESOURCE_ID = " . $this->initAlias . ".ID
			")
		);

		if ($has)
		{
			$result->where($filter);
		}
		else
		{
			$result->whereNot($filter);
		}
	}

	/**
	 * @param ConditionTree $result
	 * @param string $filterKey
	 * @param class-string<DataManager> $tableClass
	 * @return void
	 */
	private function applyHasSkusFilter(ConditionTree $result, string $filterKey, string $tableClass): void
	{
		$isFilterSet = (
			isset($this->filter[$filterKey])
			&& is_array($this->filter[$filterKey])
			&& !empty($this->filter[$filterKey])
		);

		if (!$isFilterSet)
		{
			return;
		}

		$skuIds = array_unique(array_map('intval', $this->filter[$filterKey]));

		$resourceIds = array_column(
			Application::getConnection()->query("
				SELECT RESOURCE_ID
				FROM " . $tableClass::getTableName() . "
				WHERE
					SKU_ID IN (" . implode(', ', $skuIds) . ")
				GROUP BY RESOURCE_ID
				HAVING COUNT(1) = " . count($skuIds) . "
			")->fetchAll(),
			'RESOURCE_ID'
		);

		$result->whereIn('ID', empty($resourceIds) ? [0] : $resourceIds);
	}
}
