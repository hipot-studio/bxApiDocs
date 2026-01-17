<?php

namespace Bitrix\Rest\V3\Structures\Ordering;

use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Attributes\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exceptions\InvalidOrderException;
use Bitrix\Rest\V3\Exceptions\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exceptions\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structures\Structure;

final class OrderStructure extends Structure
{
	/** @var OrderItem[] */
	protected array $items = [];

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		$structure = new self();

		$value = (array)$value;

		if (!empty($value))
		{
			/** @var Dto $dto */
			$dto = $dtoClass::create();

			$fields = $dto->getFields();

			foreach ($value as $property => $order)
			{
				if ($property === 0) // The order should be a JSON object, not a JSON array
				{
					throw new InvalidOrderException($order);
				}

				if (!isset($fields[$property]))
				{
					throw new UnknownDtoPropertyException($dto->getShortName(), $property);
				}

				if (!$fields[$property]->isSortable())
				{
					throw new DtoFieldRequiredAttributeException($dto->getShortName(), $property, Sortable::class);
				}

				// check order asc/desc
				$itemOrder = Order::tryFrom(strtoupper($order));

				if ($itemOrder === null)
				{
					throw new InvalidOrderException($order);
				}

				$structure->items[] = new OrderItem($property, $itemOrder);
			}
		}

		return $structure;
	}

	public function getItems(): array
	{
		return $this->items;
	}

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
