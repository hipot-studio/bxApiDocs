<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Type\Date;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Dto\Mapping\MapperRegistry;
use Bitrix\Rest\V3\Exception\LogicException;

/**
 * Base of the mappers of the order aggregate.
 *
 * A mapper declares the domain column and the value type of every stored scalar property of its
 * DTO; a nested DTO is handed over to its own #[MappedBy] mapper by the core, so every level of
 * the aggregate filters by its own part of the select exactly once.
 *
 * The same declaration is the single place where a DTO property meets its domain column, so the
 * write path reads it through {@see getColumnMap()} instead of writing the pairs down twice.
 *
 * A property the domain computes has no column at all and is declared apart, in
 * {@see getComputedFieldMap()}. It is mapped like any other, but it stays out of the column
 * dictionaries: those serve the listing and the write path, and a column is exactly what such a
 * property lacks there.
 *
 * Two rules hold everywhere, including nested items:
 *  - a property outside the requested select is never touched, so it stays uninitialized
 *    and never reaches the response;
 *  - a property whose key is absent from the raw item is never touched either. The mapper
 *    selects and casts, it does not invent values.
 */
abstract class AbstractOrderMapper extends Mapper
{
	protected const INT = 'int';
	protected const FLOAT = 'float';
	protected const STRING = 'string';
	protected const BOOL = 'bool';
	protected const DATE = 'date';
	protected const RAW = 'raw';

	/**
	 * @return class-string<Dto>
	 */
	abstract protected function getDtoClass(): string;

	/**
	 * @return array<string, array{string, string}> property => [domain column, value type]
	 */
	abstract protected function getFieldMap(): array;

	/**
	 * Properties the domain computes: a value type without a column of its own.
	 *
	 * @return array<string, string> property => value type
	 */
	protected function getComputedFieldMap(): array
	{
		return [];
	}

	/**
	 * @return list<string>
	 */
	public function getComputedProperties(): array
	{
		return array_keys($this->getComputedFieldMap());
	}

	/**
	 * The mapper of a part of the aggregate, addressed by the class of its data type.
	 *
	 * The resolution is the one of the core: #[MappedBy] read by MapperRegistry. The narrowing is
	 * what the write path needs on top of reading, the column dictionaries below.
	 *
	 * @param class-string<Dto> $dtoClass
	 */
	public static function forDto(string $dtoClass): self
	{
		$mapper = MapperRegistry::getForDto($dtoClass);
		if (!$mapper instanceof self)
		{
			throw new LogicException(
				new \Exception("DTO class {$dtoClass} is not mapped by a mapper of the order aggregate."),
			);
		}

		return $mapper;
	}

	/**
	 * @return array<string, string> property => domain column
	 */
	public function getColumnMap(): array
	{
		$map = [];
		foreach ($this->getFieldMap() as $property => [$column])
		{
			$map[$property] = $column;
		}

		return $map;
	}

	/**
	 * Domain columns holding a Y/N flag: the filter bridge needs them to translate booleans.
	 *
	 * @return list<string>
	 */
	public function getBooleanColumns(): array
	{
		$columns = [];
		foreach ($this->getFieldMap() as [$column, $type])
		{
			if ($type === self::BOOL)
			{
				$columns[] = $column;
			}
		}

		return $columns;
	}

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection($this->getDtoClass());
		foreach ($items as $item)
		{
			if (is_array($item))
			{
				$collection->add($this->mapItem($item, $fields));
			}
		}

		return $collection;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<int|string, mixed> $fields
	 */
	protected function mapItem(array $raw, array $fields): Dto
	{
		$dtoClass = $this->getDtoClass();
		$dto = new $dtoClass();

		foreach ($this->getFieldMap() as $property => [$column, $type])
		{
			if (!$this->isSelected($property, $fields))
			{
				continue;
			}

			$key = $this->resolveKey($raw, $property, $column);
			if ($key === null)
			{
				continue;
			}

			$dto->{$property} = $this->cast($raw[$key], $type);
		}

		foreach ($this->getComputedFieldMap() as $property => $type)
		{
			if (!$this->isSelected($property, $fields) || !array_key_exists($property, $raw))
			{
				continue;
			}

			$dto->{$property} = $this->cast($raw[$property], $type);
		}

		$this->autoMapRelations($dto, $raw, $fields);

		return $dto;
	}

	/**
	 * The service hands DTO property names, a caller reading the domain directly hands columns.
	 *
	 * @param array<string, mixed> $raw
	 */
	protected function resolveKey(array $raw, string $property, string $column): ?string
	{
		if (array_key_exists($property, $raw))
		{
			return $property;
		}

		return array_key_exists($column, $raw) ? $column : null;
	}

	/**
	 * @param array<int|string, mixed> $fields Flat list or structured select from SelectStructure.
	 */
	protected function isSelected(string $property, array $fields): bool
	{
		return $fields === []
			|| in_array($property, $fields, true)
			|| array_key_exists($property, $fields)
		;
	}

	protected function cast(mixed $value, string $type): mixed
	{
		if ($type === self::RAW)
		{
			return is_string($value) || is_array($value) ? $value : null;
		}

		if ($type === self::DATE)
		{
			return $value instanceof Date ? $value : null;
		}

		// An empty numeric, date or flag value stays empty in the response, as in V1.
		if ($value === null || $value === '')
		{
			return null;
		}

		return match ($type)
		{
			self::INT => (int)$value,
			self::FLOAT => (float)$value,
			self::BOOL => $this->toBool($value),
			default => (string)$value,
		};
	}

	/**
	 * The domain keeps flags as Y/N chars; the transport contract is boolean.
	 */
	protected function toBool(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		return is_string($value) ? strtoupper($value) === 'Y' : (bool)$value;
	}
}
