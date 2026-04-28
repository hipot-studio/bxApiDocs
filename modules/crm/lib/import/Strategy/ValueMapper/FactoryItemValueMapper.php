<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Item\Contact;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use CCrmOwnerType;

final class FactoryItemValueMapper
{
	public function __construct(
		private readonly string $fieldId,
		private readonly int $entityTypeId,
	)
	{
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$factory = Container::getInstance()->getFactory($this->entityTypeId);
		if ($factory === null)
		{
			return FieldProcessResult::skip();
		}

		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->fieldId);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$itemName = $row[$columnIndex] ?? null;
		if (empty($itemName))
		{
			return FieldProcessResult::skip();
		}

		$item = $this->findItem($factory, $itemName);
		if ($item === null)
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[$this->fieldId] = $item->getId();

		return FieldProcessResult::success();
	}

	private function findItem(Factory $factory, string $itemName): ?Item
	{
		$items = $factory->getItemsFilteredByPermissions([
			'select' => [
				Item::FIELD_NAME_ID,
			],
			'filter' => match ($this->entityTypeId) {
				CCrmOwnerType::Contact => [
					Contact::FIELD_NAME_FULL_NAME => $itemName,
				],
				default => [
					Item::FIELD_NAME_TITLE => $itemName,
				],
			},
			'limit' => 1,
		]);

		return $items[0] ?? null;
	}
}
