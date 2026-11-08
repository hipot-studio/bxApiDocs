<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

/**
 * A field of a dictionary value, and the one place its capabilities are declared: the column the storage
 * keeps it in, the values it accepts, the operators it may be filtered by and whether values can be
 * ordered by it.
 *
 * WHY ONE PLACE. Both sides of the domain ask the same questions of a field - the scenario, checking what
 * a caller asked for, and the repository, building the query. A second copy of the answers would be the
 * copy that goes stale.
 *
 * THE THREE SETS. The published set is the case set itself: all four fields always leave the domain, and
 * honouring `select` belongs to the transport. The filterable set is the case set as well - every field
 * has a non-empty operator set - and the sortable set is {@see sortable()}: ordering by `isSystem` says
 * nothing a caller can use.
 *
 * `isSystem` is compared as the `'N'`/`'Y'` it is stored as, so a comparison by it is lexicographic and
 * "greater than false" coincides with "equal to true". The mapping itself is done by the field of the ORM
 * entity, not here: a condition carries `true`/`false` and never the stored letter.
 *
 * @internal
 */
enum DictionaryField: string
{
	case Id = 'id';
	case Name = 'name';
	case Sort = 'sort';
	case IsSystem = 'isSystem';

	/** Column of the status table this field lives in. */
	public function getColumn(): string
	{
		return match ($this)
		{
			self::Id => 'STATUS_ID',
			self::Name => 'NAME',
			self::Sort => 'SORT',
			self::IsSystem => 'SYSTEM',
		};
	}

	public function supportsOperator(DictionaryFilterOperator $operator): bool
	{
		return match ($operator)
		{
			DictionaryFilterOperator::Equal,
			DictionaryFilterOperator::NotEqual,
			DictionaryFilterOperator::In,
			DictionaryFilterOperator::Greater,
			DictionaryFilterOperator::GreaterOrEqual,
			DictionaryFilterOperator::Less,
			DictionaryFilterOperator::LessOrEqual => true,
			DictionaryFilterOperator::Between => $this === self::Sort,
		};
	}

	/** Whether $value is a value of this field at all, one element of a set or a bound included. */
	public function acceptsValue(mixed $value): bool
	{
		return match ($this)
		{
			self::Id, self::Name => is_string($value),
			self::Sort => is_int($value),
			self::IsSystem => is_bool($value),
		};
	}

	public function isSortable(): bool
	{
		return $this !== self::IsSystem;
	}

	/** @return self[] */
	public static function sortable(): array
	{
		return array_values(array_filter(self::cases(), static fn(self $field): bool => $field->isSortable()));
	}
}
