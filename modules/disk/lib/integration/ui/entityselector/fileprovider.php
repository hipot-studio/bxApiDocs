<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Ui\EntitySelector;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Search\SearchFilterBuilder;
use Bitrix\Disk\Storage;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

/**
 * Files of the storage given by the option `storageId` that the current user can read. Item id is the file id.
 */
final class FileProvider extends BaseProvider
{
	public const ENTITY_ID = 'disk-file';

	private const LIMIT = 30;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options['storageId'] = (int)($options['storageId'] ?? 0);
	}

	public function isAvailable(): bool
	{
		return (int)CurrentUser::get()->getId() > 0;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addRecentItems($this->findItems([]));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchFilter = (new SearchFilterBuilder())->buildFilter($searchQuery->getQuery());
		$items = $searchFilter === [] ? [] : $this->findItems([$searchFilter]);
		if (count($items) >= self::LIMIT)
		{
			$searchQuery->setCacheable(false);
		}

		$dialog->addItems($items);
	}

	public function getItems(array $ids): array
	{
		$ids = array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0);

		return $ids === [] ? [] : $this->findItems(['@ID' => $ids]);
	}

	/**
	 * @return Item[]
	 */
	private function findItems(array $filter): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$storage = Storage::loadById($this->getOption('storageId'));
		if ($userId <= 0 || !$storage)
		{
			return [];
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$storage->getSecurityContext($userId),
			[
				'select' => ['ID', 'NAME'],
				'filter' => [
					...$filter,
					'=STORAGE_ID' => $storage->getId(),
					'=TYPE' => ObjectTable::TYPE_FILE,
					'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
				'order' => ['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'],
				'limit' => self::LIMIT,
			],
			['ID', 'CREATED_BY'],
		);

		$items = [];
		foreach (ObjectTable::getList($parameters) as $row)
		{
			$items[] = new Item([
				'id' => (int)$row['ID'],
				'entityId' => self::ENTITY_ID,
				'title' => $row['NAME'],
			]);
		}

		return $items;
	}
}
