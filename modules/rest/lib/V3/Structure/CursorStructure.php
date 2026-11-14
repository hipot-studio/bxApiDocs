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

final class CursorStructure extends Structure implements ReferencesDtoFields
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

			$field = $dto->getFields()->getMeta($value['field']);
			if ($field === null)
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
			$itemValue = FieldsConverter::convertValueByFieldMeta($field, $value['value']);

			if (!FieldsValidator::validateFieldMetaValue($field, $itemValue))
			{
				$typeLabel = $field->getDynamicEnumProvider() !== null
					? (new \ReflectionClass($field->getDynamicEnumProvider()))->getShortName()
					: $field->getPropertyType();
				throw new InvalidRequestFieldTypeException($field->getPropertyName(), $typeLabel);
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

	/**
	 * @return list<string>
	 * @throws InvalidPaginationException
	 */
	public static function getReferencedFields(mixed $value): array
	{
		$field = is_array($value) ? ($value['field'] ?? null) : null;
		if (empty($field))
		{
			return [];
		}

		if (!is_string($field))
		{
			throw new InvalidPaginationException(['field' => $field]);
		}

		return [$field];
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
