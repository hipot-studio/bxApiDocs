<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * Shared read-side mechanics for V2 Item repositories. Subclasses pin a single ORM
 * {@see DataManager} class via {@see getTableClass()}; everything else is reused.
 *
 * @internal
 */
abstract class AbstractItemRepository implements ItemRepositoryInterface
{
	/**
	 * @return class-string<DataManager>|null `null` means "this repository is currently unbound"
	 *                                          (e.g. smart-process Type config row was deleted),
	 *                                          and read methods short-circuit to empty results.
	 */
	abstract protected function getTableClass(): ?string;

	public function getById(int $id, array $select): ?EntityObject
	{
		$rows = $this->getList($select, (new ConditionTree())->where('ID', $id));

		return $rows[0] ?? null;
	}

	public function getByIds(array $ids, array $select): array
	{
		if (empty($ids))
		{
			return [];
		}

		return $this->getList($select, (new ConditionTree())->whereIn('ID', $ids));
	}

	public function getList(
		array $select,
		array|ConditionTree|null $filter = null,
		?array $order = null,
		?int $limit = null,
		?int $offset = null,
		?array $runtime = null,
	): array
	{
		$dataClass = $this->getTableClass();
		if ($dataClass === null)
		{
			return [];
		}

		if ($this->shouldSkipIdsHack($select, $filter, $runtime))
		{
			return $this->execute($dataClass, $select, $filter, $order, $limit, $offset, $runtime);
		}

		$ids = $this->fetchIds($dataClass, $filter, $order, $limit, $offset, $runtime);
		if (empty($ids))
		{
			return [];
		}

		$query = $dataClass::query()
			->setSelect($this->appendOrderFieldsToSelect($select, $order))
			->whereIn('ID', $ids)
		;
		if (!empty($order))
		{
			$query->setOrder($order);
		}

		return $query->fetchCollection()->getAll();
	}

	public function getCount(array|ConditionTree|null $filter = null, ?array $runtime = null): int
	{
		$dataClass = $this->getTableClass();
		if ($dataClass === null)
		{
			return 0;
		}

		if (empty($runtime))
		{
			return $dataClass::getCount($this->isEmptyFilter($filter) ? [] : $filter);
		}

		$query = $dataClass::query()
			->addSelect(new ExpressionField('COUNT', 'COUNT(DISTINCT %s)', 'ID'))
		;
		$this->applyFilter($query, $filter);
		$this->applyRuntime($query, $runtime);
		$row = $query->fetch();

		return (int)($row['COUNT'] ?? 0);
	}

	public function createIdQuery(array|ConditionTree|null $filter = null, ?array $runtime = null): ?Query
	{
		$dataClass = $this->getTableClass();
		if ($dataClass === null)
		{
			return null;
		}

		$query = $dataClass::query()->setSelect(['ID']);
		$this->applyFilter($query, $filter);
		$this->applyRuntime($query, $runtime);
		if (!empty($runtime))
		{
			$query->setDistinct();
		}

		return $query;
	}

	public function ensureParentFieldReferences(int $entityTypeId): void
	{
		$dataClass = $this->getTableClass();
		if ($dataClass === null)
		{
			return;
		}

		\Bitrix\Crm\Service\Container::getInstance()
			->getParentFieldManager()
			->addParentFieldsReferences($dataClass::getEntity(), $entityTypeId)
		;
	}

	/**
	 * @param class-string<DataManager> $dataClass
	 * @return int[]
	 */
	private function fetchIds(
		string $dataClass,
		array|ConditionTree|null $filter,
		?array $order,
		?int $limit,
		?int $offset,
		?array $runtime,
	): array
	{
		$select = ['ID'];
		if (!empty($order))
		{
			foreach (array_keys($order) as $orderField)
			{
				if (is_string($orderField) && $orderField !== 'ID')
				{
					$select[] = $orderField;
				}
			}
		}

		$query = $dataClass::query()->setSelect($this->appendOrderFieldsToSelect($select, $order));
		$this->applyFilter($query, $filter);
		$this->applyRuntime($query, $runtime);
		if (!empty($runtime))
		{
			$query->setDistinct();
		}
		if (!empty($order))
		{
			$query->setOrder($order);
		}
		if ($limit !== null)
		{
			$query->setLimit($limit);
		}
		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		$ids = [];
		foreach ($query->exec() as $row)
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * @param class-string<DataManager> $dataClass
	 * @param string[] $select
	 * @return EntityObject[]
	 */
	private function execute(
		string $dataClass,
		array $select,
		array|ConditionTree|null $filter,
		?array $order,
		?int $limit,
		?int $offset,
		?array $runtime,
	): array
	{
		$query = $dataClass::query()->setSelect($select);
		$this->applyFilter($query, $filter);
		$this->applyRuntime($query, $runtime);
		if (!empty($order))
		{
			$query->setOrder($order);
		}
		if ($limit !== null)
		{
			$query->setLimit($limit);
		}
		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		return $query->fetchCollection()->getAll();
	}

	/** @param string[] $select @param array<string, string>|null $order @return string[] */
	private function appendOrderFieldsToSelect(array $select, ?array $order): array
	{
		foreach (array_keys($order ?? []) as $orderField)
		{
			if (is_string($orderField) && !in_array($orderField, $select, true))
			{
				$select[] = $orderField;
			}
		}

		return $select;
	}

	private function applyRuntime(\Bitrix\Main\ORM\Query\Query $query, ?array $runtime): void
	{
		if (empty($runtime))
		{
			return;
		}

		foreach ($runtime as $name => $field)
		{
			$query->registerRuntimeField($name, $field);
		}
	}

	private function applyFilter(Query $query, array|ConditionTree|null $filter): void
	{
		if ($this->isEmptyFilter($filter))
		{
			return;
		}

		if ($filter instanceof ConditionTree)
		{
			$query->where($filter);

			return;
		}

		$query->setFilter($filter);
	}

	private function isEmptyFilter(array|ConditionTree|null $filter): bool
	{
		if ($filter === null)
		{
			return true;
		}

		return $filter instanceof ConditionTree
			? !$filter->hasConditions()
			: empty($filter)
		;
	}

	private function shouldSkipIdsHack(array $select, array|ConditionTree|null $filter, ?array $runtime): bool
	{
		// Runtime fields (permission INNER JOINs) can multiply rows — keep the two-stage read
		// so LIMIT/OFFSET act on distinct primaries.
		if (!empty($runtime))
		{
			return false;
		}

		if (count($select) === 1 && reset($select) === 'ID')
		{
			return true;
		}

		if ($filter instanceof ConditionTree && $this->isStrictIdFilter($filter))
		{
			return true;
		}

		return false;
	}

	private function isStrictIdFilter(ConditionTree $filter): bool
	{
		$conditions = $filter->getConditions();
		if (count($conditions) !== 1)
		{
			return false;
		}

		$condition = $conditions[0];
		if (!$condition instanceof Condition)
		{
			return false;
		}

		if ($condition->getColumn() !== 'ID')
		{
			return false;
		}

		return in_array($condition->getOperator(), ['=', '==', 'in'], true);
	}
}
