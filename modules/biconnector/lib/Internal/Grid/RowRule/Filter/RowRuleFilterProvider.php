<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Filter;

use Bitrix\BIConnector\Internal\Grid\RowRule\Settings\RowRuleSettings;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

final class RowRuleFilterProvider extends EntityDataProvider
{
	public const FIELD_TABLE = 'TABLE_CODE';
	public const FIELD_AUTHOR = 'CREATED_BY_ID';
	public const FIELD_EDITOR = 'UPDATED_BY_ID';
	public const FIELD_IS_USED = 'IS_USED';

	public const VALUE_YES = 'Y';
	public const VALUE_NO = 'N';

	public function __construct(
		private readonly RowRuleSettings $settings,
	)
	{
	}

	public function getSettings(): RowRuleSettings
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
			self::FIELD_TABLE => $this->createField(self::FIELD_TABLE, [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_FILTER_TABLE') ?? '',
				'default' => true,
				'type' => 'list',
				'partial' => true,
			]),
			self::FIELD_AUTHOR => $this->createField(self::FIELD_AUTHOR, [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_FILTER_AUTHOR') ?? '',
				'default' => true,
				'type' => 'entity_selector',
				'partial' => true,
			]),
			self::FIELD_EDITOR => $this->createField(self::FIELD_EDITOR, [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_FILTER_UPDATED_BY') ?? '',
				'type' => 'entity_selector',
				'partial' => true,
			]),
			self::FIELD_IS_USED => $this->createField(self::FIELD_IS_USED, [
				'name' => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_FILTER_IS_USED') ?? '',
				'default' => true,
				'type' => 'list',
				'partial' => true,
			]),
		];
	}

	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === self::FIELD_TABLE)
		{
			return [
				'params' => ['multiple' => 'Y'],
				'items' => $this->settings->getTables(),
			];
		}

		if ($fieldID === self::FIELD_AUTHOR || $fieldID === self::FIELD_EDITOR)
		{
			return $this->getUserEntitySelectorParams(
				$fieldID . '_filter',
				['fieldName' => $fieldID],
			);
		}

		if ($fieldID === self::FIELD_IS_USED)
		{
			return [
				'items' => [
					self::VALUE_YES => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_FILTER_IS_USED_YES') ?? '',
					self::VALUE_NO => Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_FILTER_IS_USED_NO') ?? '',
				],
			];
		}

		return null;
	}

	/**
	 * Reduces the raw main.ui.filter value to the fields of the rule list; empty values are dropped.
	 */
	public function prepareFilterValue(array $rawFilterValue): array
	{
		$result = [];

		$tableCodes = $this->parseCodes($rawFilterValue[self::FIELD_TABLE] ?? null);
		if ($tableCodes)
		{
			$result[self::FIELD_TABLE] = $tableCodes;
		}

		$authorIds = $this->parseIds($rawFilterValue[self::FIELD_AUTHOR] ?? null);
		if ($authorIds)
		{
			$result[self::FIELD_AUTHOR] = $authorIds;
		}

		$editorIds = $this->parseIds($rawFilterValue[self::FIELD_EDITOR] ?? null);
		if ($editorIds)
		{
			$result[self::FIELD_EDITOR] = $editorIds;
		}

		$isUsed = $rawFilterValue[self::FIELD_IS_USED] ?? null;
		if ($isUsed === self::VALUE_YES || $isUsed === self::VALUE_NO)
		{
			$result[self::FIELD_IS_USED] = $isUsed;
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function parseCodes(mixed $value): array
	{
		$codes = [];
		foreach ((array)$value as $item)
		{
			if (is_string($item) && $item !== '')
			{
				$codes[] = $item;
			}
		}

		return array_values(array_unique($codes));
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
}
