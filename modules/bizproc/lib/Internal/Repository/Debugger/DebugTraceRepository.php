<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugTraceCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;
use Bitrix\Bizproc\Internal\Exception\Debugger\PersistenceException;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable;
use Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection;
use Bitrix\Bizproc\Internal\Repository\Mapper\DebugTraceOrmMapper;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceFilter;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\SystemException;
use Exception;

final class DebugTraceRepository implements DebugTraceRepositoryInterface
{
	private DebugTraceOrmMapper $debugTraceOrmMapper;

	public function __construct(DebugTraceOrmMapper $debugTraceOrmMapper = new DebugTraceOrmMapper())
	{
		$this->debugTraceOrmMapper = $debugTraceOrmMapper;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findByDebugSessionId(
		int $debugSessionId,
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): DebugTraceCollection
	{
		return $this->getList(
			$limit,
			$offset,
			new DebugTraceFilter(['DEBUG_SESSION_ID' => $debugSessionId]),
			$sort,
			$select
		);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getList(
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): DebugTraceCollection
	{
		$query = DebugTraceTable::query()
			->setSelect($select ?: ['*'])
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}

		if ($sort !== null)
		{
			$query->setOrder($sort);
		}

		$ormCollection = $query->fetchCollection();
		$traceCollection = new DebugTraceCollection();

		foreach ($ormCollection as $ormModel)
		{
			$traceCollection->add($this->debugTraceOrmMapper->convertFromOrm($ormModel));
		}

		return $traceCollection;
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws PersistenceException
	 */
	public function saveCollection(DebugTraceCollection $debugTraceCollection): void
	{
		$ormDebugTraceCollection = new EO_DebugTrace_Collection();

		foreach ($debugTraceCollection as $debugTraceEntity)
		{
			$ormDebugTraceCollection->add($this->debugTraceOrmMapper->convertToOrm($debugTraceEntity));
		}

		$result = $ormDebugTraceCollection->save(true);

		if ($result->isSuccess())
		{
			return;
		}

		throw PersistenceException::saveError()->setResult($result);
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws PersistenceException
	 */
	public function save(DebugTrace $debugTraceEntity): void
	{
		$ormObject = $this->debugTraceOrmMapper->convertToOrm($debugTraceEntity);
		$result = $ormObject->save();

		if (!$result->isSuccess() && empty($result->getId()))
		{
			throw PersistenceException::saveError();
		}

		$debugTraceEntity->setId($result->getId());
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function deleteByDebugSessionId(int $debugSessionId): void
	{
		DebugTraceTable::deleteByFilter([
			'=DEBUG_SESSION_ID' => $debugSessionId,
		]);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws Exception
	 */
	public function delete(int $id): void
	{
		$debugTraceEntity = $this->first($id);

		if ($debugTraceEntity === null || $debugTraceEntity->isNew())
		{
			throw PersistenceException::deleteError();
		}

		$result = DebugTraceTable::delete($debugTraceEntity->getId());

		if (!$result->isSuccess())
		{
			throw PersistenceException::deleteError();
		}
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function first(int $id): ?DebugTrace
	{
		$ormModel = DebugTraceTable::query()
			->where('ID', $id)
			->fetchObject()
		;

		return $ormModel ? $this->debugTraceOrmMapper->convertFromOrm($ormModel) : null;
	}
}
