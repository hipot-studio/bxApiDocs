<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Filter;

use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Assembler\ShareRegistryRowAssembler;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Internal\Services\Share\ShareStatusResolver;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\NumberType;
use Bitrix\Main\Web\Json;

final class ShareRegistryFilterProvider extends EntityDataProvider
{
	public const FIELD_AUTHOR = 'AUTHOR_ID';
	public const FIELD_DASHBOARD = 'DASHBOARD_ID';
	public const FIELD_STATUS = 'STATUS';
	public const FIELD_NOT_OPENED_DAYS = 'NOT_OPENED_DAYS';
	public const FIELD_ONLY_BANNED = 'ONLY_BANNED';
	public const FIELD_DISABLED_BY = 'DISABLED_BY_ID';
	// main.ui.filter drops FIND from the prepared value, so the search string is kept under its own key
	public const FIELD_SEARCH = 'SEARCH';

	public function __construct(
		private readonly Settings $settings,
	)
	{
	}

	public function getSettings(): Settings
	{
		return $this->settings;
	}

	protected function getFieldName($fieldID)
	{
		return $fieldID;
	}

	public function prepareFields(): array
	{
		return [
			self::FIELD_AUTHOR => $this->createField(self::FIELD_AUTHOR, [
				'name' => Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_FILTER_AUTHOR') ?? '',
				'default' => true,
				'type' => 'entity_selector',
				'partial' => true,
			]),
			self::FIELD_DASHBOARD => $this->createField(self::FIELD_DASHBOARD, [
				'name' => Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_FILTER_DASHBOARD') ?? '',
				'default' => true,
				'type' => 'list',
				'partial' => true,
			]),
			self::FIELD_STATUS => $this->createField(self::FIELD_STATUS, [
				'name' => Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_FILTER_STATUS') ?? '',
				'default' => true,
				'type' => 'list',
				'partial' => true,
			]),
			self::FIELD_NOT_OPENED_DAYS => $this->createField(self::FIELD_NOT_OPENED_DAYS, [
				'name' => Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_FILTER_NOT_OPENED_DAYS') ?? '',
				'default' => true,
				'type' => 'number',
				// The field means "not opened for more than N days", so "less" and "range" have no meaning here.
				'exclude' => [NumberType::LESS, NumberType::RANGE],
			]),
			self::FIELD_ONLY_BANNED => $this->createField(self::FIELD_ONLY_BANNED, [
				'name' => Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_FILTER_ONLY_BANNED') ?? '',
				'default' => true,
				'type' => 'checkbox',
			]),
			self::FIELD_DISABLED_BY => $this->createField(self::FIELD_DISABLED_BY, [
				'name' => Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_FILTER_DISABLED_BY') ?? '',
				'default' => true,
				'type' => 'entity_selector',
				'partial' => true,
			]),
		];
	}

	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === self::FIELD_AUTHOR || $fieldID === self::FIELD_DISABLED_BY)
		{
			return $this->getUserEntitySelectorParams(
				$fieldID . '_filter',
				['fieldName' => $fieldID],
			);
		}

		if ($fieldID === self::FIELD_DASHBOARD)
		{
			return [
				'params' => ['multiple' => 'Y'],
				'items' => $this->getSharedDashboards(),
			];
		}

		if ($fieldID === self::FIELD_STATUS)
		{
			$items = [];
			foreach (ShareStatusResolver::getStatuses() as $status)
			{
				$items[$status] = ShareRegistryRowAssembler::getStatusTitle($status);
			}

			return [
				'params' => ['multiple' => 'Y'],
				'items' => $items,
			];
		}

		return null;
	}

	/**
	 * Reduces the raw main.ui.filter value to the registry fields; empty values are dropped.
	 */
	public function prepareFilterValue(array $rawFilterValue): array
	{
		$result = [];

		$authorIds = $this->parseIds($rawFilterValue[self::FIELD_AUTHOR] ?? null);
		if ($authorIds)
		{
			$result[self::FIELD_AUTHOR] = $authorIds;
		}

		$disabledByIds = $this->parseIds($rawFilterValue[self::FIELD_DISABLED_BY] ?? null);
		if ($disabledByIds)
		{
			$result[self::FIELD_DISABLED_BY] = $disabledByIds;
		}

		$dashboardIds = $this->parseIds($rawFilterValue[self::FIELD_DASHBOARD] ?? null);
		if ($dashboardIds)
		{
			$result[self::FIELD_DASHBOARD] = $dashboardIds;
		}

		$statuses = array_values(array_intersect(
			ShareStatusResolver::getStatuses(),
			(array)($rawFilterValue[self::FIELD_STATUS] ?? []),
		));
		if ($statuses)
		{
			$result[self::FIELD_STATUS] = $statuses;
		}

		$notOpenedDays = $this->parseDays($rawFilterValue);
		if ($notOpenedDays !== null)
		{
			$result[self::FIELD_NOT_OPENED_DAYS] = $notOpenedDays;
		}

		if (($rawFilterValue[self::FIELD_ONLY_BANNED] ?? null) === 'Y')
		{
			$result[self::FIELD_ONLY_BANNED] = 'Y';
		}

		$search = is_string($rawFilterValue['FIND'] ?? null) ? trim($rawFilterValue['FIND']) : '';
		if ($search !== '')
		{
			$result[self::FIELD_SEARCH] = $search;
		}

		return $result;
	}

	/**
	 * Every dashboard with a link, regardless of the view right: a controller may not see the report itself.
	 *
	 * @return array<int, string>
	 */
	private function getSharedDashboards(): array
	{
		$rows = SupersetDashboardShareTable::query()
			->setSelect(['DASHBOARD_ID', 'DASHBOARD_TITLE' => 'DASHBOARD.TITLE'])
			->setDistinct()
			->setOrder(['DASHBOARD_TITLE' => 'ASC'])
			->fetchAll()
		;

		$items = [];
		foreach ($rows as $row)
		{
			$items[(int)$row['DASHBOARD_ID']] = (string)$row['DASHBOARD_TITLE'];
		}

		return $items;
	}

	/**
	 * @return int[]
	 */
	private function parseIds(mixed $value): array
	{
		$ids = [];
		foreach ((array)$value as $item)
		{
			// entity selector may keep an item as a JSON pair ["user", 1]
			if (is_string($item) && str_starts_with($item, '['))
			{
				try
				{
					$pair = Json::decode($item);
					$item = is_array($pair) ? ($pair[1] ?? null) : null;
				}
				catch (\Throwable)
				{
					$item = null;
				}
			}

			if (is_numeric($item) && (int)$item > 0)
			{
				$ids[] = (int)$item;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * "Exact" and "more" both read as "more than N days"; a "less" or "range" value left in a saved preset is
	 * dropped rather than read inverted.
	 */
	private function parseDays(array $rawFilterValue): ?int
	{
		$field = self::FIELD_NOT_OPENED_DAYS;
		$operation = $rawFilterValue[$field . '_numsel'] ?? NumberType::SINGLE;
		if (!in_array($operation, [NumberType::SINGLE, NumberType::MORE], true))
		{
			return null;
		}

		foreach ([$field . '_from', $field] as $key)
		{
			$value = $rawFilterValue[$key] ?? null;
			if (is_numeric($value) && (int)$value >= 0)
			{
				return (int)$value;
			}
		}

		return null;
	}
}
