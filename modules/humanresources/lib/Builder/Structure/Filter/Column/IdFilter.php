<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Filter\Column;

use Bitrix\HumanResources\Enum\ConditionMode;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\IntegerCollection;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

final class IdFilter extends BaseColumnFilter
{
	/**
	 * @param IntegerCollection|null $ids
	 * @param ConditionMode|null $filterMode - whether to include or exclude the given IDs. Use with caution with other filters,
	 * it might not work if other filters are applied.
	 */
	public function __construct(
		public ?IntegerCollection $ids = null,
		public ?ConditionMode $filterMode = ConditionMode::Inclusion,
	)
	{}

	protected function getFieldName(): string
	{
		return 'ID';
	}

	protected function getItems(): array
	{
		return $this->ids?->getItems() ?? [];
	}

	public static function fromId(int $id): self
	{
		return new self(
			new IntegerCollection($id)
		);
	}

	/**
	 * @param int[] $ids
	 * @param ConditionMode $filterMode
	 *
	 * @return self
	 */
	public static function fromIds(array $ids, ConditionMode $filterMode = ConditionMode::Inclusion): self
	{
		if (empty($ids))
		{
			return new self();
		}

		return new self(new IntegerCollection(...$ids), $filterMode);
	}

	public static function fromAccessCodes(array $accessCodes): self
	{
		if (empty($accessCodes))
		{
			return new self();
		}

		$nodeIds = InternalContainer::getNodeAccessCodeService()->getNodeIdsByAccessCodes($accessCodes);

		return new self(new IntegerCollection(...$nodeIds));
	}

	public function prepareFilter(): ConditionTree
	{
		$conditionTree = new ConditionTree();

		if (empty($this->getItems()))
		{
			return $conditionTree;
		}

		if ($this->filterMode === ConditionMode::Exclusion)
		{
			$conditionTree->whereNotIn(
				$this->getFieldByQueryContext($this->getFieldName()),
				$this->getItems(),
			);
		}
		else
		{
			$conditionTree->whereIn(
				$this->getFieldByQueryContext($this->getFieldName()),
				$this->getItems(),
			);
		}

		return $conditionTree;
	}
}