<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Filter;

use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatChartProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatDashboardProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatDatasetProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatSourceProvider;
use Bitrix\BIConnector\Internal\Grid\UsageStat\Settings\UsageStatSettings;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI;

final class UsageStatFilterProvider extends EntityDataProvider
{
	public function __construct(
		private readonly UsageStatSettings $settings,
	)
	{
	}

	public function getSettings(): UsageStatSettings
	{
		return $this->settings;
	}

	public function prepareFields(): array
	{
		$fields = [
			'TIMESTAMP_X' => $this->createField('TIMESTAMP_X', [
				'name' => Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_FIELD_TIMESTAMP_X') ?? '',
				'default' => true,
				'type' => 'date',
				'time' => true,
				'data' => [
					'exclude' => [
						UI\Filter\DateType::TOMORROW,
						UI\Filter\DateType::NEXT_DAYS,
						UI\Filter\DateType::NEXT_WEEK,
						UI\Filter\DateType::NEXT_MONTH,
					],
				],
			]),
		];

		if ($this->settings->isBiBuilderService())
		{
			$fields += [
				'EXTERNAL_DASHBOARD_ID' => $this->createField('EXTERNAL_DASHBOARD_ID', [
					'name' => Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_FIELD_DASHBOARD') ?? '',
					'default' => true,
					'type' => 'entity_selector',
					'partial' => true,
				]),
				'EXTERNAL_CHART_ID' => $this->createField('EXTERNAL_CHART_ID', [
					'name' => Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_FIELD_CHART') ?? '',
					'default' => true,
					'type' => 'entity_selector',
					'partial' => true,
				]),
				'EXTERNAL_DATASET_ID' => $this->createField('EXTERNAL_DATASET_ID', [
					'name' => Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_FIELD_DATASET') ?? '',
					'default' => true,
					'type' => 'entity_selector',
					'partial' => true,
				]),
			];
		}

		$fields += [
			'SOURCE_ID' => $this->createField('SOURCE_ID', [
				'name' => Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_FIELD_SOURCE_ID') ?? '',
				'default' => true,
				'type' => 'entity_selector',
				'partial' => true,
			]),
		];

		return $fields;
	}

	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === 'SOURCE_ID')
		{
			return $this->buildEntitySelectorFieldData(UsageStatSourceProvider::ENTITY_ID);
		}

		if ($fieldID === 'EXTERNAL_DASHBOARD_ID')
		{
			return $this->buildEntitySelectorFieldData(UsageStatDashboardProvider::ENTITY_ID);
		}

		if ($fieldID === 'EXTERNAL_CHART_ID')
		{
			return $this->buildEntitySelectorFieldData(UsageStatChartProvider::ENTITY_ID);
		}

		if ($fieldID === 'EXTERNAL_DATASET_ID')
		{
			return $this->buildEntitySelectorFieldData(UsageStatDatasetProvider::ENTITY_ID);
		}

		return null;
	}

	public function prepareFilterValue(array $rawFilterValue): array
	{
		$rawFilterValue = parent::prepareFilterValue($rawFilterValue);

		if (!empty($rawFilterValue['TIMESTAMP_X_from']))
		{
			$rawFilterValue['>=TIMESTAMP_X'] = $rawFilterValue['TIMESTAMP_X_from'];
		}
		if (!empty($rawFilterValue['TIMESTAMP_X_to']))
		{
			$rawFilterValue['<=TIMESTAMP_X'] = $rawFilterValue['TIMESTAMP_X_to'];
		}

		return $rawFilterValue;
	}

	private function buildEntitySelectorFieldData(string $entityId): array
	{
		return [
			'params' => [
				'multiple' => 'N',
				'dialogOptions' => [
					'context' => $entityId,
					'entities' => [
						[
							'id' => $entityId,
							'options' => ['filter' => true],
							'dynamicLoad' => true,
							'dynamicSearch' => true,
						],
					],
					'dropdownMode' => true,
					'compactView' => true,
					'showAvatars' => false,
				],
			],
		];
	}
}
