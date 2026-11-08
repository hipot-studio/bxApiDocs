<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Sort;

use Bitrix\HumanResources\Enum\SortDirection;

class NodeMemberSort implements SortInterface
{
	private string $alias = '';

	private array $orderColumns = [];

	public function __construct(
		public readonly ?SortDirection $id = null,
		public readonly ?SortDirection $entityId = null,
	)
	{
	}

	/**
	 * @return array<string, string>
	 */
	public function prepareSort(): array
	{
		$this->addIdSort();
		$this->addEntityIdSort();

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

	private function addEntityIdSort(): void
	{
		if ($this->entityId === null)
		{
			return;
		}

		$this->orderColumns[$this->alias . 'ENTITY_ID'] = $this->entityId->value;
	}

	public function setCurrentAlias(string $alias): static
	{
		$this->alias = $alias . '.';

		return $this;
	}
}