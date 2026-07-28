<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Public\Provider\UsageStat\UsageStatProvider;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

class UsageStatSourceProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-usage-stat-source';
	private const TAB_ID = 'all';

	private UsageStatProvider $usageStatProvider;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
		$this->usageStatProvider = new UsageStatProvider();
	}

	public function isAvailable(): bool
	{
		return
			CurrentUser::get()->canDoOperation('biconnector_key_manage')
			|| AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG)
		;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BIC_USAGE_STAT_ENTITY_SELECTOR_SOURCE_TAB'),
			'stub' => true,
		]));

		foreach ($this->makeItems($this->usageStatProvider->getUsedTables()) as $item)
		{
			$dialog->addRecentItem($item);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);
		$query = trim($searchQuery->getQuery());

		$entries = $this->usageStatProvider->getUsedTables();
		if ($query !== '')
		{
			$needle = mb_strtolower($query);
			$entries = array_filter(
				$entries,
				static fn (string $label): bool => mb_stripos($label, $needle) !== false,
			);
		}

		$dialog->addItems($this->makeItems($entries));
	}

	public function getItems(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$keysIds = array_flip(array_map('strval', $ids));
		$entries = array_intersect_key($this->usageStatProvider->getUsedTables(), $keysIds);

		return $this->makeItems($entries);
	}

	/**
	 * @param array<string, string> $entries
	 *
	 * @return Item[]
	 */
	private function makeItems(array $entries): array
	{
		$items = [];
		foreach ($entries as $id => $label)
		{
			$items[] = new Item([
				'id' => $id,
				'entityId' => self::ENTITY_ID,
				'title' => $label,
				'tabs' => [self::TAB_ID],
			]);
		}

		return $items;
	}
}
