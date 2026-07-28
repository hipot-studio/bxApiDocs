<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Public\Provider\UsageStat\UsageStatProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

class UsageStatChartProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-usage-stat-chart';
	private const TAB_ID = 'all';
	protected const ELEMENTS_LIMIT = 50;

	private UsageStatProvider $usageStatProvider;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
		$this->usageStatProvider = new UsageStatProvider();
	}

	public function isAvailable(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BIC_USAGE_STAT_ENTITY_SELECTOR_CHART_TAB'),
			'stub' => true,
		]));

		$entries = $this->usageStatProvider->searchUsedCharts(null, null, self::ELEMENTS_LIMIT);
		foreach ($this->makeItems($entries) as $item)
		{
			$dialog->addRecentItem($item);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		$entries = $this->usageStatProvider->searchUsedCharts(
			$searchQuery->getQuery(),
			null,
			self::ELEMENTS_LIMIT,
		);
		$dialog->addItems($this->makeItems($entries));
	}

	public function getItems(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$entries = $this->usageStatProvider->searchUsedCharts(null, array_map('strval', $ids));

		return $this->makeItems($entries);
	}

	/**
	 * @param array<string, array{name: string, type: 'chart'|'filter'}> $entries
	 *
	 * @return Item[]
	 */
	private function makeItems(array $entries): array
	{
		$items = [];
		foreach ($entries as $id => $entry)
		{
			$title = $entry['type'] === 'chart'
				? Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_CHART_TYPE_CHART', ['#ELEMENT_NAME#' => $entry['name']])
				: Loc::getMessage('BIC_USAGE_STAT_GRID_FILTER_CHART_TYPE_FILTER', ['#ELEMENT_NAME#' => $entry['name']])
			;

			$items[] = new Item([
				'id' => $id,
				'entityId' => self::ENTITY_ID,
				'title' => $title ?? $entry['name'],
				'tabs' => [self::TAB_ID],
			]);
		}

		return $items;
	}
}
