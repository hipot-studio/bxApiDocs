<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Category\Deal;

use Bitrix\Crm\Category\Entity\DealCategory;
use Bitrix\Crm\Category\Entity\DealCategoryTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

class DealCategoryRepository
{
	private const COUNT_FIELD = 'ROW_COUNT';

	private ?int $allCount = null;

	public function countAll(): int
	{
		$this->allCount ??= $this->count();

		return $this->allCount;
	}

	public function countBefore(array $category, array $order): int
	{
		$sortField = (string)array_key_first($order);
		$direction = $order[$sortField] ?? 'ASC';
		$comparison = $direction === 'DESC' ? '>' : '<';
		$id = (int)$category['ID'];

		if ($sortField === 'ID')
		{
			if ($id === 0)
			{
				return $direction === 'DESC' ? $this->countAll() : 0;
			}

			return $this->count(Query::filter()->where('ID', $comparison, $id));
		}

		$fieldValue = $category[$sortField];

		$sameValueFilter = Query::filter()
			->where($sortField, $fieldValue)
			->where('ID', $comparison, $id)
		;
		$beforeFilter = Query::filter()
			->logic('or')
			->where($sortField, $comparison, $fieldValue)
			->where($sameValueFilter)
		;

		return $this->count($beforeFilter);
	}

	public function fetchById(int $id): ?array
	{
		$row = DealCategoryTable::query()
			->setSelect([
				'ID',
				'NAME',
				'SORT',
				'CREATED_DATE',
			])
			->where('ID', $id)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	public function fetchPage(array $order, int $offset, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		return DealCategoryTable::query()
			->setSelect([
				'ID',
				'NAME',
				'SORT',
				'CREATED_DATE',
			])
			->setOrder($order)
			->setOffset(max(0, $offset))
			->setLimit($limit)
			->fetchAll()
		;
	}

	public function fetchMaxId(): int
	{
		$row = DealCategoryTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return (int)($row['ID'] ?? 0);
	}

	public function fetchIdsAfter(int $lastId, int $upperId, int $limit): array
	{
		if ($limit <= 0 || $lastId >= $upperId)
		{
			return [];
		}

		$rows = DealCategoryTable::query()
			->setSelect(['ID'])
			->where('ID', '>', $lastId)
			->where('ID', '<=', $upperId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(
			static fn(array $row): int => (int)$row['ID'],
			$rows,
		);
	}

	/**
	 * @return array<int, DealCategory>
	 */
	public function fetchByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(
			$ids,
			static fn(mixed $id): bool => is_int($id) && $id > 0,
		)));
		if ($ids === [])
		{
			return [];
		}

		$objects = DealCategoryTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $ids)
			->fetchCollection()
		;
		$categories = [];
		foreach ($objects as $object)
		{
			$category = new DealCategory($object);
			$categories[$category->getId()] = $category;
		}

		return $categories;
	}

	protected function count(?ConditionTree $filter = null): int
	{
		$query = DealCategoryTable::query()
			->addSelect(new ExpressionField(self::COUNT_FIELD, 'COUNT(*)'))
		;

		if ($filter !== null)
		{
			$query->where($filter);
		}

		$row = $query->fetch();

		return (int)($row[self::COUNT_FIELD] ?? 0);
	}
}
