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

class UsageStatDashboardProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-usage-stat-dashboard';
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
			'title' => Loc::getMessage('BIC_USAGE_STAT_ENTITY_SELECTOR_DASHBOARD_TAB'),
			'stub' => true,
		]));

		$entries = $this->usageStatProvider->searchUsedDashboards(null, null, self::ELEMENTS_LIMIT);
		foreach ($this->makeItems($entries) as $item)
		{
			$dialog->addRecentItem($item);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		$entries = $this->usageStatProvider->searchUsedDashboards(
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

		$entries = $this->usageStatProvider->searchUsedDashboards(null, array_map('strval', $ids));

		return $this->makeItems($entries);
	}

	/**
	 * @param array<int|string, string> $entries
	 *
	 * @return Item[]
	 */
	private function makeItems(array $entries): array
	{
		$items = [];
		foreach ($entries as $id => $name)
		{
			$items[] = new Item([
				'id' => (string)$id,
				'entityId' => self::ENTITY_ID,
				'title' => $name,
				'tabs' => [self::TAB_ID],
			]);
		}

		return $items;
	}
}
