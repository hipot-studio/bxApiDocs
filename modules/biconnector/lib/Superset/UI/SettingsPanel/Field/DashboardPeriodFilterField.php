<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\UI\Period\DefaultPeriodLabelBuilder;

final class DashboardPeriodFilterField extends EntityEditorField
{
	public const FIELD_NAME = 'FILTER_PERIOD';
	public const FIELD_ENTITY_EDITOR_TYPE = 'dashboardTimePeriod';

	private Dashboard $dashboard;

	public function __construct(string $id, Dashboard $dashboard)
	{
		parent::__construct($id);

		$this->dashboard = $dashboard;
	}

	public function getName(): string
	{
		return self::FIELD_NAME;
	}

	public function getType(): string
	{
		return self::FIELD_ENTITY_EDITOR_TYPE;
	}

	public function getFieldInitialData(): array
	{
		$filter = new EmbeddedFilter\DateTime($this->dashboard);

		$filterPeriod = $filter->getPeriod();
		if ($filter->hasDefaultFilter())
		{
			$filterPeriod = EmbeddedFilter\DateTime::PERIOD_DEFAULT;
		}

		return [
			'DATE_FILTER_START' => $filter->getDateStart(),
			'DATE_FILTER_END' => $filter->getDateEnd(),
			'FILTER_PERIOD' => $filterPeriod,
		];
	}

	protected function getFieldInfoData(): array
	{
		return [
			'items' => self::getPeriodList(),
			'dateStartFieldName' => 'DATE_FILTER_START',
			'dateEndFieldName' => 'DATE_FILTER_END',
			'isHtml' => true,
		];
	}

	private static function getPeriodList(): array
	{
		$periods = [
			EmbeddedFilter\DateTime::PERIOD_LAST_7,
			EmbeddedFilter\DateTime::PERIOD_LAST_30,
			EmbeddedFilter\DateTime::PERIOD_LAST_90,
			EmbeddedFilter\DateTime::PERIOD_LAST_180,
			EmbeddedFilter\DateTime::PERIOD_LAST_365,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_WEEK,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_MONTH,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_YEAR,
			EmbeddedFilter\DateTime::PERIOD_RANGE,
			EmbeddedFilter\DateTime::PERIOD_NONE,
		];

		$items = [];
		foreach ($periods as $period)
		{
			$items[] = [
				'NAME' => EmbeddedFilter\DateTime::getPeriodName($period),
				'VALUE' => $period,
			];
		}

		$items[] = self::getDefaultPeriodItem();

		return $items;
	}

	private static function getDefaultPeriodItem(): array
	{
		$defaultPeriodLabel = (new DefaultPeriodLabelBuilder())->build();
		$defaultFilterName = $defaultPeriodLabel['prefixText']
			. "<span class='ui-color-light biconnector-default-filter-prefix'>{$defaultPeriodLabel['valueText']}</span>"
			. $defaultPeriodLabel['suffixText']
		;

		return [
			'NAME' => $defaultFilterName,
			'VALUE' => EmbeddedFilter\DateTime::PERIOD_DEFAULT,
		];
	}
}
