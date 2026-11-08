<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Filter;

use Bitrix\BIConnector\Superset\Filter\Provider\DashboardDataProvider;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\UI\Filter\Type;

final class DashboardFilterBuilder
{
	private readonly Filter $filter;

	public function __construct(private readonly ?int $userId = null)
	{
		$gridId = DashboardGrid::SUPERSET_DASHBOARD_GRID_ID;
		$this->filter = new Filter($gridId, new DashboardDataProvider(new Settings(['ID' => $gridId])));
	}

	/**
	 * Turns preset values into the ORM filter of the report list.
	 *
	 * @param array $presetFields Values as Options stores them, suffixes included.
	 */
	public function build(array $presetFields, string $searchString = ''): DashboardFilterResult
	{
		$sourceFields = $this->filter->getFieldArrays();

		// Date and number suffixes are expanded here, not by Filter::getValue():
		// DashboardDataProvider does not implement prepareListFilterParam(), so without this
		// step Filter::getValue() would silently drop DATE_CREATE, DATE_MODIFY and ID.
		$values = Options::fetchFieldValuesFromFilterSettings(['fields' => $presetFields], [], $sourceFields);

		$resolved = (new StaleConditionResolver($this->userId))->resolve($values);
		$values = $resolved->fields;

		if ($searchString !== '')
		{
			$values['FIND'] = $searchString;
		}

		$rawValue = $values + Type::getLogicFilter($values, $sourceFields);

		return new DashboardFilterResult($this->filter->getValue($rawValue), $resolved->stale);
	}
}
