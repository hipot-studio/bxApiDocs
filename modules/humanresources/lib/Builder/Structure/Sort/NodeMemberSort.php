<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Sort;

use Bitrix\HumanResources\Enum\SortDirection;

class NodeMemberSort implements SortInterface
{
	private string $alias = '';

	private array $orderColumns = [];

	public function __construct(
		public readonly ?SortDirection $id= null,
	)
	{
	}

	/**
	 * @return array<string, string>
	 */
	public function prepareSort(): array
	{
		$this->addIdSort();

		return $this->orderColumns;
	}

	private function addIdSort(): void
	{
		if ($this->id === null)
		{
			return;
		}

		$this->orderColumns[$this->alias . 'ID'] = $this->id->value;
	}

	public function setCurrentAlias(string $alias): static
	{
		$this->alias = $alias . '.';

		return $this;
	}
}