<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Sort\SortInterface;

final class CompatNodeSort implements SortInterface
{
	private string $alias = '';

	public function prepareSort(): array
	{
		return [
			$this->alias . 'SORT' => 'asc',
			$this->alias . 'ID' => 'asc',
		];
	}

	public function setCurrentAlias(string $alias): static
	{
		$this->alias = $alias . '.';

		return $this;
	}
}
