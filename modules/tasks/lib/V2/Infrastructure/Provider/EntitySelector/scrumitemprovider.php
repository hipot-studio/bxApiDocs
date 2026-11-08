<?php

namespace Bitrix\Tasks\V2\Infrastructure\Provider\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Tasks\Integration\SocialNetwork\Group;
use Bitrix\Tasks\Scrum\Service\EntityService;
use Bitrix\Tasks\Scrum\Service\ItemQueryService;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

final class ScrumItemProvider extends BaseProvider
{
	private const ENTITY_ID = 'scrum-item';
	private const MAX_COUNT = 30;

	private readonly int $userId;

	public function __construct(array $options = [], ?int $userId = null)
	{
		parent::__construct();

		$this->userId = $userId ?? (int)CurrentUser::get()->getId();
		$this->options['groupId'] = (int)($options['groupId'] ?? 0);
		$this->options['entityId'] = (int)($options['entityId'] ?? 0);
		$this->options['excludeIds'] = $this->normalizeIds($options['excludeIds'] ?? []);
	}

	public function isAvailable(): bool
	{
		if ($this->userId <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		$groupId = $this->getOption('groupId');
		$entity = (new EntityService())->getEntityById($this->getOption('entityId'));
		if ($entity->isEmpty() || $entity->getGroupId() !== $groupId)
		{
			return false;
		}

		$group = Workgroup::getById($groupId);

		return
			$group
			&& $group->isScrumProject()
			&& Group::canReadGroupTasks($this->userId, $groupId);
	}

	public function getItems(array $ids): array
	{
		return $this->getScrumItems(ids: $ids);
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getScrumItems(ids: $ids);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$items = $this->getScrumItems($searchQuery);

		$dialog->addItems($items);

		if (count($items) >= self::MAX_COUNT)
		{
			$searchQuery->setCacheable(false);
		}
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addRecentItems($this->getScrumItems());
	}

	private function getScrumItems(?SearchQuery $searchQuery = null, array $ids = []): array
	{
		$ids = $this->normalizeIds($ids);
		$excludeIds = $ids ? [] : $this->getOption('excludeIds');
		$query = $searchQuery?->getQuery() ?? '';

		$itemsData = (new ItemQueryService($this->userId))->getRootItems(
			$this->getOption('entityId'),
			$ids,
			$excludeIds,
			$query,
		);

		$items = [];
		foreach ($itemsData as $itemData)
		{
			$items[] = new Item([
				'entityId' => self::ENTITY_ID,
				'id' => (int)$itemData['ID'],
				'title' => (string)$itemData['NAME'],
				'tabs' => 'recents',
			]);
		}

		return $items;
	}

	private function normalizeIds(array $ids): array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[$id] = $id;
			}
		}

		return array_values($normalizedIds);
	}
}
