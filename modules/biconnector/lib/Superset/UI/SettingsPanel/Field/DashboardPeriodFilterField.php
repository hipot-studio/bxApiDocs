<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\UI\Period\DefaultPeriodLabelBuilder;

final class DashboardPeriodFilterField extends PeriodFilterField
{
	public const FIELD_ENTITY_EDITOR_TYPE = 'dashboardTimePeriod';

	private Dashboard $dashboard;

	public function __construct(string $id, Dashboard $dashboard)
	{
		parent::__construct($id);

		$this->dashboard = $dashboard;
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

	protected static function getPeriodList(): array
	{
		$commonList = parent::getPeriodList();
		$commonList[] = [
			'NAME' => EmbeddedFilter\DateTime::getPeriodName(EmbeddedFilter\DateTime::PERIOD_NONE),
			'VALUE' => EmbeddedFilter\DateTime::PERIOD_NONE,
		];

		$defaultPeriodLabel = (new DefaultPeriodLabelBuilder())->build();
		$defaultFilterName = htmlspecialcharsbx($defaultPeriodLabel['prefixText'])
			. "<span class='ui-color-light biconnector-default-filter-prefix'>"
			. htmlspecialcharsbx($defaultPeriodLabel['valueText'])
			. '</span>'
			. htmlspecialcharsbx($defaultPeriodLabel['suffixText'])
		;

		$defaultFilter = [
			'NAME' => $defaultFilterName,
			'VALUE' => EmbeddedFilter\DateTime::PERIOD_DEFAULT,
		];

		return [
			...$commonList,
			$defaultFilter,
		];
	}

	public function getFieldInfoData(): array
	{
		$infoData = parent::getFieldInfoData();
		$infoData['isHtml'] = true;

		return $infoData;
	}
}
