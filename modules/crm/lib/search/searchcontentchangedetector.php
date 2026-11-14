<?php

namespace Bitrix\Crm\Search;

use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield;
use Bitrix\Main\Type\Date;

/**
 * Decides whether an update of an item changed the data its search content is built from.
 */
final class SearchContentChangeDetector
{
	public function __construct(private readonly SearchContentBuilder $builder)
	{
	}

	public function isRebuildNeeded(Item $itemBeforeSave, Item $item): bool
	{
		$fieldNames = $this->builder->getSearchChangeFields();
		if ($fieldNames === null)
		{
			return true;
		}

		foreach ($fieldNames as $fieldName)
		{
			if ($this->isFieldChanged($fieldName, $itemBeforeSave, $item))
			{
				return true;
			}
		}

		return false;
	}

	private function isFieldChanged(string $fieldName, Item $itemBeforeSave, Item $item): bool
	{
		if (!$itemBeforeSave->hasField($fieldName) || !$item->hasField($fieldName))
		{
			return true;
		}

		// the multifields are loaded by a query of their own; untouched on both items they did not change
		if (
			$fieldName === Item::FIELD_NAME_FM
			&& !$itemBeforeSave->isChanged($fieldName)
			&& !$item->isChanged($fieldName)
		)
		{
			return false;
		}

		try
		{
			return !$this->isEqual($itemBeforeSave->remindActual($fieldName), $item->get($fieldName));
		}
		catch (\Throwable)
		{
			// a value that can not be compared counts as changed: a needless rebuild is safe, an outdated index is not
			return true;
		}
	}

	private function isEqual(mixed $previous, mixed $current): bool
	{
		if ($previous instanceof Multifield\Collection || $current instanceof Multifield\Collection)
		{
			return $previous instanceof Multifield\Collection
				&& $current instanceof Multifield\Collection
				&& $previous->isEqualTo($current)
			;
		}

		return $this->normalize($previous) === $this->normalize($current);
	}

	/**
	 * @throws \UnexpectedValueException for a value of an unknown kind
	 */
	private function normalize(mixed $value): mixed
	{
		if ($value === null || $value === '' || $value === false)
		{
			return null;
		}

		if (is_scalar($value))
		{
			return (string)$value;
		}

		if ($value instanceof Date)
		{
			return $value->format('Y-m-d H:i:s');
		}

		if (is_array($value))
		{
			// order is kept: the order of the contacts changes the content
			return array_map(fn(mixed $element): mixed => $this->normalize($element), $value);
		}

		throw new \UnexpectedValueException('Value of type ' . get_debug_type($value) . ' can not be compared');
	}
}
