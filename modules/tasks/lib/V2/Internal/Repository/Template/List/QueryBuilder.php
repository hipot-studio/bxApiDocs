<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder\ModifierFactory;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder\QueryModifierInterface;

class QueryBuilder
{
	public function __construct(private readonly ModifierFactory $modifierFactory)
	{
	}

	public function buildListQuery(Select $select, Filter $filter, Order $order, int $limit, int $offset): Query
	{
		$query = TemplateTable::query()
			->setLimit($limit)
			->setOffset($offset)
		;

		foreach ($this->collectModifiers($select, $filter, $order) as $modifier)
		{
			$query = $modifier->modify($query);
		}

		return $query;
	}

	/**
	 * @return QueryModifierInterface[]
	 */
	protected function collectModifiers(Select $select, Filter $filter, Order $order): array
	{
		$builders = [];
		foreach ($select->getList() as $item)
		{
			$builders[] = $this->modifierFactory->createSelectModifier($item);
		}

		foreach ($filter->getList() as $item)
		{
			$builders[] = $this->modifierFactory->createFilterModifier(...$item);
		}

		foreach ($order->getList() as $item)
		{
			$builders[] = $this->modifierFactory->createOrderModifier(...$item);
		}

		return $builders;
	}
}
