<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Values of the group condition in the filter of the tables section.
 *
 * The dialog names exactly the groups the section itself draws rows for: both read
 * TableGroupRepository, so a group closed for the person cannot be picked in the filter and cannot
 * be suggested by the search either.
 */
class TableGroupProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-table-group';

	private TableGroupRepository $groupRepository;

	/**
	 * @param array $options Options of the dialog; the provider needs none of its own.
	 * @param TableGroupRepository|null $groupRepository Rows of the groups; built for the current
	 *                                                  user when omitted.
	 */
	public function __construct(array $options = [], ?TableGroupRepository $groupRepository = null)
	{
		parent::__construct();

		$this->groupRepository = $groupRepository ?? new TableGroupRepository();
	}

	public function isAvailable(): bool
	{
		global $USER;

		return is_object($USER) && $USER->isAuthorized();
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addItems($this->getItems([]));
		$dialog->addTab(new Tab([
			'id' => 'groups',
			'title' => Loc::getMessage('BICONNECTOR_TABLE_GROUP_PROVIDER_TAB_LABEL'),
		]));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$query = mb_strtolower(trim($searchQuery->getQuery()));
		if ($query === '')
		{
			return;
		}

		$found = [];
		foreach ($this->getVisibleGroups() as $group)
		{
			if (mb_strpos(mb_strtolower($group['NAME']), $query) !== false)
			{
				$found[] = $this->makeItem($group);
			}
		}

		$dialog->addItems($found);
	}

	public function getItems(array $ids): array
	{
		$requestedIds = array_flip(array_map('intval', $ids));

		$items = [];
		foreach ($this->getVisibleGroups() as $group)
		{
			if (!empty($requestedIds) && !isset($requestedIds[$group['ID']]))
			{
				continue;
			}

			$items[] = $this->makeItem($group);
		}

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function getVisibleGroups(): array
	{
		return $this->groupRepository->getGroupRows();
	}

	private function makeItem(array $group): Item
	{
		return new Item([
			'id' => $group['ID'],
			'entityId' => self::ENTITY_ID,
			'title' => $group['NAME'],
			'tabs' => 'groups',
			'saveable' => false,
			'avatar' => $group['IS_SYSTEM']
				? '/bitrix/js/ui/icons/disk/images/ui-icon-air-folder-24.svg'
				: '/bitrix/js/ui/icons/disk/images/ui-icon-air-folder-person.svg',
			'avatarOptions' => [
				'borderRadius' => '4px',
			],
		]);
	}
}
