<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

/**
 * What a caller of the dictionary domain asks to select by: a tree of conditions, each node carrying its
 * own logic and negation.
 *
 * A TREE, NOT A LIST, because the common REST filter admits groups and negation, and a flat list would
 * silently drop them - a request meaning "not (A or B)" would be answered as if it had asked for
 * something else.
 *
 * The dictionary itself is not part of a filter and cannot be: every operation is addressed to one
 * dictionary, and the condition selecting it is added by the repository, not by a caller.
 *
 * @internal
 */
final class DictionaryFilter
{
	/** @var array<int, DictionaryFilterCondition|self> */
	private array $conditions = [];

	public function __construct(
		private readonly bool $or = false,
		private readonly bool $negative = false,
	)
	{
	}

	/** @param string|int|bool|array<int, string|int|bool> $value */
	public function where(
		DictionaryField $field,
		DictionaryFilterOperator $operator,
		string|int|bool|array $value,
	): self
	{
		$this->conditions[] = new DictionaryFilterCondition($field, $operator, $value);

		return $this;
	}

	public function add(self $filter): self
	{
		$this->conditions[] = $filter;

		return $this;
	}

	/** @return array<int, DictionaryFilterCondition|self> */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function isOr(): bool
	{
		return $this->or;
	}

	public function isNegative(): bool
	{
		return $this->negative;
	}
}
