<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\UI\EntitySelector;

use Bitrix\Crm\Service\Container;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

class WebFormProvider extends BaseProvider
{
	public const ENTITY_ID = 'web_form';

	public function __construct(array $options)
	{
		parent::__construct();

		$this->options = $options;
	}

	final public function isAvailable(): bool
	{
		return Container::getInstance()->getUserPermissions()->webForm()->canRead();
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
		return $this->makeItems(['@ID' => $ids]);
	}

	final public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	private function makeItems(array $filter = []): array
	{
		$items = [];
		$result = \Bitrix\Crm\WebForm\Internals\FormTable::getDefaultTypeList([
			'filter' => $filter,
			'select' => ['ID', 'NAME'],
			'order' => ['NAME' => 'ASC', 'ID' => 'ASC'],
		]);
		foreach ($result as $row)
		{
			$items[] = $this->makeItem($row['ID'], $row['NAME']);
		}

		return $items;
	}

	private function makeItem(string $id, string $title): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => static::ENTITY_ID,
			'title' => $title,
		]);
	}
}
