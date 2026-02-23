<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\Main\Localization\Loc;

class StorageProvider extends BaseProvider
{
	public const ENTITY_ID = 'bizproc-storage';

	public function __construct(array $options)
	{
		parent::__construct();

		$this->options = $options;
	}

	final public function isAvailable(): bool
	{
		return $this->getCurrentUserId() > 0;
	}

	final public function fillDialog(Dialog $dialog): void
	{
		$items = $this->makeItems();

		array_walk(
			$items,
			static function (Item $item) use ($dialog) {
				$dialog->addRecentItem($item);
			}
		);
	}

	final public function getItems(array $ids): array
	{
		return $this->makeItemsByIds($ids);
	}

	final public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	private function makeItems(): array
	{
		$provider = new StorageTypeProvider();

		$collection = $provider->getStoragesByFilter(select: ['ID', 'TITLE']);

		$items = [];
		foreach ($collection as $storageItem)
		{
			$id = $storageItem->getId();
			$title = $storageItem->getTitle();

			$items[] = $this->makeItem($id, $title);
		}

		return $items;
	}

	private function makeItemsByIds(array $ids): array
	{
		$ids = array_filter(array_map('intval', $ids));
		if (empty($ids))
		{
			return [];
		}

		$provider = new StorageTypeProvider();
		$collection = $provider->getStoragesByFilter(['@ID' => $ids], ['ID', 'TITLE']);

		$items = [];
		foreach ($collection as $storageItem)
		{
			$id = $storageItem->getId();
			$title = $storageItem->getTitle();

			$items[] = $this->makeItem($id, $title);
		}

		return $items;
	}

	private function makeItem(int $id, string $title): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => static::ENTITY_ID,
			'title' => $title,
			'linkTitle' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_STORAGE_LINK') ?? '',
			'link' => "/bitrix/components/bitrix/bizproc.storage.item.list/?storageId={$id}",
		]);
	}

	protected function getCurrentUserId(): int
	{
		return (int)(CurrentUser::get()->getId());
	}
}
