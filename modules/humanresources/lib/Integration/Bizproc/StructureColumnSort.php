<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Builder\Structure\Sort\SortInterface;
use Bitrix\HumanResources\Enum\SortDirection;

/**
 * Ascending order by the columns named, which is what the ceiling of a structure read is cut in.
 * {@see \Bitrix\HumanResources\Builder\Structure\Sort\NodeSort} cannot order by the id, and the id is
 * what makes the cut of a read repeatable.
 */
final class StructureColumnSort implements SortInterface
{
	private string $alias = '';

	/**
	 * @param list<string> $columns
	 */
	public function __construct(private readonly array $columns)
	{
	}

	public function prepareSort(): array
	{
		$order = [];
		foreach ($this->columns as $column)
		{
			$order[$this->alias . $column] = SortDirection::Asc->value;
		}

		return $order;
	}

	public function setCurrentAlias(string $alias): static
	{
		$this->alias = $alias . '.';

		return $this;
	}
}
