<?php

namespace Bitrix\Rest\V3\Dto\Meta;

/**
 * Immutable description of all fields of a DTO class, in field order and without duplicate names.
 * The cache array it is built from is not kept.
 *
 * @internal
 */
final class FieldsMeta
{
	/** @var list<FieldMeta> */
	public readonly array $list;

	/** @var array<string, int> field name => position in $list */
	public readonly array $index;

	/** @var list<AbsentValue> one marker per position in $list */
	public readonly array $emptyValues;

	/**
	 * @param list<FieldMeta> $list
	 * @param array<string, int> $index
	 */
	private function __construct(array $list, array $index)
	{
		$this->list = $list;
		$this->index = $index;
		$this->emptyValues = array_fill(0, count($list), AbsentValue::Marker);
	}

	/**
	 * Every record is checked as DtoField::fromArray() does; of records with the same name the first one wins.
	 */
	public static function fromCacheArray(array $data): self
	{
		$list = [];
		$index = [];
		foreach ($data as $record)
		{
			$field = FieldMeta::fromArray($record);
			$name = $field->getPropertyName();
			if (!isset($index[$name]))
			{
				$index[$name] = count($list);
				$list[] = $field;
			}
		}

		return new self($list, $index);
	}

	public function toArray(): array
	{
		$result = [];
		foreach ($this->list as $field)
		{
			$result[] = $field->toArray();
		}

		return $result;
	}
}
