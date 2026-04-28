<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Filter\Column\Node;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\BaseColumnFilter;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

final class NodeNameFilter extends BaseColumnFilter
{
	public function __construct(
		public string $name,
		public bool $strict = false,
	)
	{}

	protected function getFieldName(): string
	{
		return 'NAME';
	}

	protected function getItems(): array
	{
		return [$this->name];
	}

	public static function fromName(string $name): self
	{
		return new self($name);
	}

	public function prepareFilter(): ConditionTree
	{
		$conditionTree = new ConditionTree();

		if (empty($this->getItems()))
		{
			return $conditionTree;
		}

		if ($this->strict)
		{
			$conditionTree->where(
				$this->getFieldByQueryContext('NAME'),
				$this->name,
			);
		}
		else
		{
			$conditionTree->whereLike(
				$this->getFieldByQueryContext('NAME'),
				'%' . $this->name . '%',
			);
		}

		return $conditionTree;
	}
}