<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Support;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductDto;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use ReflectionProperty;

final class ProductFieldsMapper
{
	private const PROPERTY_KEY_PATTERN = '/^PROPERTY_([0-9]+)$/D';
	private const PROPERTY_ID_PATTERN = '/^[0-9]+$/D';
	private const CANONICAL_PROPERTY_KEY_PATTERN = '/^PROPERTY_[1-9][0-9]*$/D';

	/**
	 * Keys of the property set that name no iblock property: only PROPERTY_{id} and a bare {id}
	 * with a positive id are property keys. Any other key would reach the record as an element
	 * or product field.
	 *
	 * @param array<array-key, mixed>|null $properties
	 * @return list<string>
	 */
	public static function findForeignPropertyKeys(?array $properties): array
	{
		if ($properties === null)
		{
			return [];
		}

		$foreignKeys = [];
		foreach (array_keys($properties) as $key)
		{
			$key = (string)$key;
			if (!self::isPropertyKey($key))
			{
				$foreignKeys[] = $key;
			}
		}

		return $foreignKeys;
	}

	/**
	 * Groups of keys of the property set naming one property, like PROPERTY_62, 62 and PROPERTY_062:
	 * the write keeps one value of them, so the value checked before it may not be the one stored.
	 *
	 * @param array<array-key, mixed>|null $properties
	 * @return list<list<string>>
	 */
	public static function findDuplicatePropertyKeys(?array $properties): array
	{
		if ($properties === null)
		{
			return [];
		}

		$keysByProperty = [];
		foreach (array_keys($properties) as $key)
		{
			$key = (string)$key;
			if (self::isPropertyKey($key))
			{
				$keysByProperty[self::normalizePropertyKey($key)][] = $key;
			}
		}

		return array_values(array_filter(
			$keysByProperty,
			static fn(array $keys): bool => count($keys) > 1,
		));
	}

	/**
	 * The one spelling of a property key: PROPERTY_{id} with the id as the write reads it, an integer.
	 * A bare identifier gets the prefix, leading zeros are dropped; any other key is kept as is.
	 */
	public static function normalizePropertyKey(string $key): string
	{
		if (preg_match(self::PROPERTY_ID_PATTERN, $key) === 1)
		{
			return 'PROPERTY_' . (int)$key;
		}

		if (preg_match(self::PROPERTY_KEY_PATTERN, $key, $matches) === 1)
		{
			return 'PROPERTY_' . (int)$matches[1];
		}

		return $key;
	}

	/**
	 * @return array<string, mixed> camelCase fields for ProductValidator / ProductFilterValidator
	 */
	public static function dtoToCamelFields(ProductDto $dto, bool $skipId = true): array
	{
		$fields = [];

		foreach (get_object_vars($dto) as $property => $value)
		{
			if ($skipId && $property === 'id')
			{
				continue;
			}

			$reflection = new ReflectionProperty($dto, $property);
			if (!$reflection->isInitialized($dto))
			{
				continue;
			}

			if ($property === 'properties')
			{
				if (is_array($value))
				{
					foreach ($value as $propKey => $propValue)
					{
						$propKey = self::normalizePropertyKey((string)$propKey);
						if (self::isPropertyKey($propKey))
						{
							$fields[$propKey] = $propValue;
						}
					}
				}

				continue;
			}

			$fields[$property] = $value;
		}

		return $fields;
	}

	/**
	 * @return array<string, mixed> UPPER_SNAKE domain fields (+ PROPERTY_*)
	 */
	public static function dtoToDomainFields(ProductDto $dto, bool $skipId = true): array
	{
		$fields = DtoFieldsConverter::toOrmFields($dto, $skipId);

		if (isset($fields['PROPERTIES']) && is_array($fields['PROPERTIES']))
		{
			foreach ($fields['PROPERTIES'] as $propKey => $propValue)
			{
				$key = self::normalizePropertyKey((string)$propKey);
				if (self::isPropertyKey($key))
				{
					$fields[$key] = $propValue;
				}
			}
			unset($fields['PROPERTIES']);
		}

		if (isset($fields['DATE_ACTIVE_FROM']))
		{
			$fields['ACTIVE_FROM'] = $fields['DATE_ACTIVE_FROM'];
			unset($fields['DATE_ACTIVE_FROM']);
		}
		if (isset($fields['DATE_ACTIVE_TO']))
		{
			$fields['ACTIVE_TO'] = $fields['DATE_ACTIVE_TO'];
			unset($fields['DATE_ACTIVE_TO']);
		}

		return $fields;
	}

	/**
	 * @return array<string, mixed> camelCase filter for ProductFilterValidator
	 */
	public static function filterToCamelArray(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		self::appendFilterConditionsCamel($filter, $result);

		return $result;
	}

	/**
	 * @return array<string, mixed> CIBlockElement-style filter
	 */
	public static function filterToDomainArray(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		self::appendFilterConditionsDomain($filter, $result);

		return $result;
	}

	/**
	 * @return list<string> UPPER select fields
	 */
	public static function selectToDomainList(?SelectStructure $select): array
	{
		if ($select === null || $select->getList() === [])
		{
			return [];
		}

		$result = [];
		foreach ($select->getList() as $field)
		{
			if ($field === 'properties')
			{
				$result[] = 'PROPERTY_*';

				continue;
			}

			$orm = strtoupper(StringHelper::camel2snake($field));
			if ($orm === 'DATE_ACTIVE_FROM')
			{
				$orm = 'ACTIVE_FROM';
			}
			elseif ($orm === 'DATE_ACTIVE_TO')
			{
				$orm = 'ACTIVE_TO';
			}
			$result[] = $orm;
		}

		return $result;
	}

	/**
	 * @return array<string, string> UPPER => ASC|DESC
	 */
	public static function orderToDomainArray(?OrderStructure $order): array
	{
		if ($order === null || $order->getItems() === [])
		{
			return ['ID' => 'ASC'];
		}

		$result = [];
		foreach ($order->getItems() as $item)
		{
			$orm = strtoupper(StringHelper::camel2snake($item->getProperty()));
			$result[$orm] = $item->getOrder()->value;
		}

		return $result;
	}

	public static function rowToDto(array $row): ProductDto
	{
		$dto = new ProductDto();
		$properties = [];

		foreach ($row as $key => $value)
		{
			if (preg_match('/^PROPERTY_\d+$/', (string)$key))
			{
				$properties[$key] = $value;

				continue;
			}

			$property = StringHelper::snake2camel(strtolower((string)$key), true);
			if ($key === 'ACTIVE_FROM')
			{
				$property = 'dateActiveFrom';
			}
			elseif ($key === 'ACTIVE_TO')
			{
				$property = 'dateActiveTo';
			}

			if (!property_exists($dto, $property) || $property === 'properties')
			{
				continue;
			}

			if (in_array($property, ['dateCreate', 'timestampX', 'dateActiveFrom', 'dateActiveTo'], true))
			{
				$dto->{$property} = self::toDateTime($value);

				continue;
			}

			if (in_array($property, ['id', 'iblockId', 'createdBy', 'modifiedBy', 'sort', 'iblockSectionId', 'type', 'vatId', 'measure', 'recurSchemeLength', 'trialPriceId'], true))
			{
				$dto->{$property} = $value === null || $value === '' ? null : (int)$value;

				continue;
			}

			if (in_array($property, ['quantity', 'quantityReserved', 'purchasingPrice', 'weight', 'length', 'width', 'height'], true))
			{
				$dto->{$property} = $value === null || $value === '' ? null : (float)$value;

				continue;
			}

			if ($property === 'iblockSection')
			{
				$dto->iblockSection = is_array($value) ? array_map('intval', $value) : null;

				continue;
			}

			$dto->{$property} = $value;
		}

		if ($properties !== [])
		{
			$dto->properties = $properties;
		}

		return $dto;
	}

	private static function isPropertyKey(string $key): bool
	{
		return preg_match(self::CANONICAL_PROPERTY_KEY_PATTERN, self::normalizePropertyKey($key)) === 1;
	}

	private static function toDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if ($value === null || $value === '' || $value === false)
		{
			return null;
		}

		try
		{
			return new DateTime((string)$value);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function appendFilterConditionsCamel(FilterStructure $filter, array &$result): void
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				self::appendFilterConditionsCamel($condition, $result);

				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			$field = (string)$condition->getLeftOperand();
			$result[self::operatorPrefix($condition->getOperator()) . $field] = $condition->getRightOperand();
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function appendFilterConditionsDomain(FilterStructure $filter, array &$result): void
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				self::appendFilterConditionsDomain($condition, $result);

				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			$field = strtoupper(StringHelper::camel2snake((string)$condition->getLeftOperand()));
			if ($field === 'DATE_ACTIVE_FROM')
			{
				$field = 'ACTIVE_FROM';
			}
			elseif ($field === 'DATE_ACTIVE_TO')
			{
				$field = 'ACTIVE_TO';
			}

			$result[self::operatorPrefix($condition->getOperator()) . $field] = $condition->getRightOperand();
		}
	}

	private static function operatorPrefix(Operator $operator): string
	{
		return match ($operator)
		{
			Operator::Equal => '=',
			Operator::NotEqual => '!',
			Operator::Greater => '>',
			Operator::GreaterOrEqual => '>=',
			Operator::Less => '<',
			Operator::LessOrEqual => '<=',
			Operator::In => '@',
			Operator::Between => '><',
		};
	}
}
