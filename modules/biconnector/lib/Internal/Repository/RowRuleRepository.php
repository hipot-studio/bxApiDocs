<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\RowRule;
use Bitrix\BIConnector\Internal\Model\RowRuleTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\RowRuleMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\SystemException;

class RowRuleRepository implements RepositoryInterface
{
	private const ORDER_FIELDS = [
		'id' => 'ID',
		'dateCreate' => 'DATE_CREATE',
		'dateModify' => 'DATE_MODIFY',
		'tableCode' => 'TABLE_CODE',
	];
	private const DEFAULT_ORDER_FIELD = 'dateModify';

	public function __construct(private readonly RowRuleMapper $mapper)
	{
	}

	/**
	 * @param mixed $id
	 * @return RowRule|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = RowRuleTable::getById((int)$id)->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	public function exists(int $id): bool
	{
		return $id > 0 && RowRuleTable::getCount(['=ID' => $id]) > 0;
	}

	/**
	 * @param EntityInterface $entity
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof RowRule)
		{
			throw new PersistenceException('Entity must be an instance of RowRule');
		}

		try
		{
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save row rule', errors: $result->getErrorMessages());
		}

		if (!$entity->getId())
		{
			$entity->setId($result->getId());
		}
	}

	/**
	 * @param mixed $id
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = RowRuleTable::delete((int)$id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete row rule', errors: $result->getErrorMessages());
		}
	}

	/**
	 * @return RowRule[]
	 */
	public function findByTableAndField(string $tableCode, string $fieldCode, ?int $excludeId = null): array
	{
		$filter = [
			'=TABLE_CODE' => $tableCode,
			'=FIELD_NAME' => $fieldCode,
		];
		if ($excludeId !== null)
		{
			$filter['!=ID'] = $excludeId;
		}

		return $this->fetchRules([
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
		]);
	}

	/**
	 * @param array $filter ['tableCode' => string[], 'createdById' => int[], 'updatedById' => int[]]
	 * @param array $order ['field' => 'id'|'dateCreate'|'dateModify'|'tableCode', 'direction' => 'asc'|'desc']
	 * @return RowRule[]
	 */
	public function findList(array $filter, array $order, int $offset, int $limit): array
	{
		return $this->fetchRules([
			'filter' => $this->buildFilter($filter),
			'order' => $this->buildOrder($order),
			'offset' => max(0, $offset),
			'limit' => max(1, $limit),
		]);
	}

	/**
	 * @param array $filter ['tableCode' => string[], 'createdById' => int[], 'updatedById' => int[]]
	 */
	public function count(array $filter): int
	{
		return RowRuleTable::getCount($this->buildFilter($filter));
	}

	/**
	 * @return RowRule[]
	 */
	private function fetchRules(array $parameters): array
	{
		$rules = [];
		$collection = RowRuleTable::getList($parameters)->fetchCollection();
		foreach ($collection as $ormModel)
		{
			$rules[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $rules;
	}

	private function buildFilter(array $filter): array
	{
		$ormFilter = [];

		if (!empty($filter['tableCode']))
		{
			$ormFilter['@TABLE_CODE'] = array_values(array_map('strval', (array)$filter['tableCode']));
		}

		if (!empty($filter['createdById']))
		{
			$ormFilter['@CREATED_BY_ID'] = array_values(array_map('intval', (array)$filter['createdById']));
		}

		if (!empty($filter['updatedById']))
		{
			$ormFilter['@UPDATED_BY_ID'] = array_values(array_map('intval', (array)$filter['updatedById']));
		}

		return $ormFilter;
	}

	private function buildOrder(array $order): array
	{
		$field = self::ORDER_FIELDS[$order['field'] ?? ''] ?? self::ORDER_FIELDS[self::DEFAULT_ORDER_FIELD];
		$direction = strtolower((string)($order['direction'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

		$ormOrder = [$field => $direction];
		if ($field !== 'ID')
		{
			// stable paging when the sort column has ties
			$ormOrder['ID'] = $direction;
		}

		return $ormOrder;
	}
}
