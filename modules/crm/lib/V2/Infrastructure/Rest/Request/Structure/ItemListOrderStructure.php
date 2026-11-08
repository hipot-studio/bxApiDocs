<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Ordering\OrderItem;
use Bitrix\Rest\V3\Structure\Structure;

final class ItemListOrderStructure extends Structure
{
	/** @param OrderItem[] $items */
	private function __construct(private readonly array $items)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			throw new InvalidOrderException($value);
		}

		foreach ($value as $order)
		{
			if (!is_string($order))
			{
				throw new InvalidOrderException($order);
			}
		}

		$items = [];
		foreach ($value as $fieldName => $order)
		{
			if (str_contains((string)$fieldName, '.'))
			{
				$dto = $dtoClass::create();
				throw new UnknownDtoPropertyException($dto->getShortName(), (string)$fieldName);
			}
			else
			{
				$dto = $dtoClass::create();
				$field = $dto->getFields()[$fieldName] ?? null;
				if (!$field instanceof DtoField)
				{
					throw new UnknownDtoPropertyException($dto->getShortName(), (string)$fieldName);
				}
				if (!$field->isSortable())
				{
					throw new DtoFieldRequiredAttributeException($dto->getShortName(), (string)$fieldName, Sortable::class);
				}
			}

			$orderValue = \Bitrix\Main\DB\Order::tryFrom(strtoupper($order));
			if ($orderValue === null)
			{
				throw new InvalidOrderException($order);
			}
			$items[] = new OrderItem((string)$fieldName, $orderValue);
		}

		return new self($items);
	}

	/** @return OrderItem[] */
	public function getItems(): array
	{
		return $this->items;
	}

	/** @return array<string, string> */
	public function getList(): array
	{
		$result = [];
		foreach ($this->items as $item)
		{
			$result[$item->getProperty()] = $item->getOrder()->value;
		}

		return $result;
	}
}
