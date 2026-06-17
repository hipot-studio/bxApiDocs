<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugSessionCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Bizproc\Internal\Exception\Debugger\DebuggerException;
use Bitrix\Bizproc\Internal\Exception\Debugger\PersistenceException;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable;
use Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession;
use Bitrix\Bizproc\Internal\Repository\Mapper\DebugSessionOrmMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\DebugTraceOrmMapper;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\SystemException;
use Exception;
use Throwable;

final class DebugSessionRepository implements DebugSessionRepositoryInterface
{
	private DebugSessionOrmMapper $debugSessionOrmMapper;
	private DebugTraceOrmMapper $debugTraceOrmMapper;

	public function __construct(
		?DebugSessionOrmMapper $debugSessionOrmMapper = new DebugSessionOrmMapper(),
		?DebugTraceOrmMapper $debugTraceOrmMapper = new DebugTraceOrmMapper(),
	)
	{
		$this->debugSessionOrmMapper = $debugSessionOrmMapper;
		$this->debugTraceOrmMapper = $debugTraceOrmMapper;
	}

	/**
	 * @throws ArgumentException
	 * @throws DebuggerException
	 * @throws PersistenceException
	 * @throws SystemException
	 */
	public function save(DebugSession $debugSessionEntity): void
	{
		$ormDebugSessionModel = $this->debugSessionOrmMapper->convertToOrm($debugSessionEntity);
		$result = $ormDebugSessionModel->save();

		if (!$result->isSuccess())
		{
			throw PersistenceException::saveError()->setResult($result);
		}

		if ($debugSessionEntity->isNew())
		{
			$debugSessionEntity->setId($result->getId());
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws Exception
	 */
	public function delete(int $id): void
	{
		$session = $this->first($id, false);

		if ($session === null || $session->isNew())
		{
			throw PersistenceException::deleteError();
		}

		$result = DebugSessionTable::delete($session->getId());

		if (!$result->isSuccess())
		{
			//TODO Logger

			throw PersistenceException::deleteError();
		}
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function first(int $id, bool $withTraces = true): ?DebugSession
	{
		$debugSessionModel = DebugSessionTable::query()
			->setSelect(['*'])
			->where('ID', $id)
			->fetchObject()
		;

		if ($debugSessionModel === null)
		{
			return null;
		}

		$debugSessionEntity = $this->debugSessionOrmMapper->convertFromOrm($debugSessionModel);

		if ($withTraces)
		{
			$this->fillDebugTrace($debugSessionEntity, $debugSessionModel);
		}

		return $debugSessionEntity;
	}

	/**
	 * @throws ArgumentException
	 */
	private function fillDebugTrace(DebugSession $debugSessionEntity, EO_DebugSession $debugSessionModel): void
	{
		$debugTracesCollection = $debugSessionModel->getDebugTraces();

		foreach ($debugTracesCollection as $debugTraceModel)
		{
			$debugSessionEntity->putTrace($this->debugTraceOrmMapper->convertFromOrm($debugTraceModel));
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function exists(int $id): bool
	{
		$result = DebugSessionTable::query()
			->where('ID', $id)
			->setSelect(['ID'])
			->fetch()
		;

		return $result !== false;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findByUserId(int $userId, bool $withTraces = false): DebugSessionCollection
	{
		$query = DebugSessionTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->setOrder(['START_TIME' => 'DESC'])
		;

		$ormDebugSessionCollection = $query->fetchCollection();
		$debugSessionCollection = new DebugSessionCollection();

		if ($ormDebugSessionCollection === null || $ormDebugSessionCollection->isEmpty())
		{
			return $debugSessionCollection;
		}

		foreach ($ormDebugSessionCollection as $ormDebugSessionModel)
		{
			$debugSessionEntity = $this->debugSessionOrmMapper->convertFromOrm($ormDebugSessionModel);

			if ($withTraces)
			{
				$this->fillDebugTrace($debugSessionEntity, $ormDebugSessionModel);
			}

			$debugSessionCollection[] = $debugSessionEntity;
		}

		return $debugSessionCollection;
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
	): DebugSessionCollection
	{
		$query = DebugSessionTable::query()
			->setSelect($select ?: ['*'])
			->setOrder($sort ?: ['START_TIME' => 'DESC'])
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

		$debugSessionCollection = new DebugSessionCollection();

		foreach (QueryHelper::decompose($query) as $ormDebugSessionModel)
		{
			$debugSessionCollection->add(
				$this->debugSessionOrmMapper->convertFromOrm($ormDebugSessionModel)
			);
		}

		return $debugSessionCollection;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByWorkflowId(string $workflowId): DebugSessionCollection
	{
		$query = DebugSessionTable::query()
			->setSelect(['*'])
			->where('WORKFLOW_ID', $workflowId)
			->setOrder(['START_TIME' => 'DESC'])
		;

		$ormDebugSessionCollection = $query->fetchCollection();
		$debugSessionCollection = new DebugSessionCollection();

		if ($ormDebugSessionCollection === null || $ormDebugSessionCollection->isEmpty())
		{
			return $debugSessionCollection;
		}

		foreach ($ormDebugSessionCollection as $ormDebugSessionModel)
		{
			$debugSessionEntity = $this->debugSessionOrmMapper->convertFromOrm($ormDebugSessionModel);
			$debugSessionCollection[] = $debugSessionEntity;
		}

		return $debugSessionCollection;
	}

	/**
	 * @throws SqlQueryException
	 */
	public function deleteOlderThan(int $days): int
	{
		$threshold = time() - ($days * 86400);
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleteTracesSql = new SqlExpression(
				'DELETE FROM ?# WHERE DEBUG_SESSION_ID IN (SELECT ID FROM ?# WHERE START_TIME < ?i)',
				DebugTraceTable::getTableName(),
				DebugSessionTable::getTableName(),
				$threshold,
			);
			$connection->query($deleteTracesSql);

			$countSql = new SqlExpression(
				'SELECT COUNT(*) as CNT FROM ?# WHERE START_TIME < ?i',
				DebugSessionTable::getTableName(),
				$threshold,
			);
			$countResult = $connection->query($countSql)->fetch();

			$deletedCount = (int)($countResult['CNT'] ?? 0);
			$deleteSessionsSql = new SqlExpression(
				'DELETE FROM ?# WHERE START_TIME < ?i',
				DebugSessionTable::getTableName(),
				$threshold,
			);
			$connection->query($deleteSessionsSql);

			$connection->commitTransaction();

			return $deletedCount;
		}
		catch (Throwable $e)
		{
			$connection->rollbackTransaction();
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return 0;
		}
	}

	/**
	 * @throws SqlQueryException
	 */
	public function deleteByUserId(int $userId): int
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$countSql = new SqlExpression(
				'SELECT COUNT(*) as CNT FROM ?# WHERE USER_ID = ?i',
				DebugSessionTable::getTableName(),
				$userId,
			);
			$countResult = $connection->query($countSql)->fetch();
			$deletedCount = (int)($countResult['CNT'] ?? 0);

			$deleteTracesSql = new SqlExpression(
				'DELETE FROM ?# WHERE DEBUG_SESSION_ID IN (SELECT ID FROM ?# WHERE USER_ID = ?i)',
				DebugTraceTable::getTableName(),
				DebugSessionTable::getTableName(),
				$userId,
			);
			$connection->query($deleteTracesSql);

			$deleteSessionsSql = new SqlExpression(
				'DELETE FROM ?# WHERE USER_ID = ?i',
				DebugSessionTable::getTableName(),
				$userId,
			);
			$connection->query($deleteSessionsSql);
			$connection->commitTransaction();

			return $deletedCount;
		}
		catch (Throwable $e)
		{
			$connection->rollbackTransaction();
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return 0;
		}
	}

	/**
	 * @throws SqlQueryException
	 */
	public function deleteAll(): int
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$countSql = new SqlExpression('SELECT COUNT(*) as CNT FROM ?#', DebugSessionTable::getTableName());
			$countResult = $connection->query($countSql)->fetch();
			$deletedCount = (int)($countResult['CNT'] ?? 0);

			$deleteTracesSql = new SqlExpression('DELETE FROM ?#', DebugTraceTable::getTableName());
			$connection->query($deleteTracesSql);

			$deleteSessionsSql = new SqlExpression('DELETE FROM ?#', DebugSessionTable::getTableName());
			$connection->query($deleteSessionsSql);

			$connection->commitTransaction();

			return $deletedCount;
		}
		catch (Throwable $e)
		{
			$connection->rollbackTransaction();
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return 0;
		}
	}
}
