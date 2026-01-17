<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class CursorStructure extends Structure
{
	protected string $field;
	protected mixed $value = null;
	protected Order $order;

	protected int $limit = 50;

	public static function create(mixed $value, ?string $dtoClass = null, ?Request $request = null): self
	{
		$structure = new self();

		if (isset($value['limit']))
		{
			if (!is_numeric($value['limit']) || $value['limit'] == 0)
			{
				throw new InvalidPaginationException(['limit' => $value['limit']]);
			}

			$structure->limit = min((int)$value['limit'], PaginationStructure::MAX_LIMIT);
		}

		if (!empty($value['field']))
		{
			/** @var Dto $dto */
			$dto = self::getDto($dtoClass);

			$fields = $dto->getFields();
			if (!isset($fields[$value['field']]))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $value['field']);
			}
			$structure->field = $value['field'];
		}
		else
		{
			throw new RequiredFieldInRequestException('cursor.field');
		}

		if ($value['value'] !== null)
		{
			$field = $dto->getFields()[$structure->field];
			$itemValue = FieldsConverter::convertValueByType($field->getPropertyType(), $value['value']);

			if (!FieldsValidator::validateTypeAndValue($field->getPropertyType(), $itemValue))
			{
				throw new InvalidRequestFieldTypeException($field->getPropertyName(), $field->getPropertyType());
			}

			$structure->value = $itemValue;
		}
		else
		{
			throw new RequiredFieldInRequestException('cursor.value');
		}

		if (!empty($value['order']))
		{
			$itemOrder = Order::tryFrom(strtoupper($value['order']));

			if ($itemOrder === null)
			{
				throw new InvalidOrderException($value['order']);
			}

			$structure->order = $itemOrder;
		}
		else
		{
			$structure->order = Order::Asc;
		}

		return $structure;
	}

	public function getField(): string
	{
		return $this->field;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getOrder(): Order
	{
		return $this->order;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}
}
