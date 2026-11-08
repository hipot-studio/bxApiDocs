<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardShareMapper;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardShareRepository implements RepositoryInterface
{
	public function __construct(private readonly SupersetDashboardShareMapper $mapper)
	{
	}

	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = SupersetDashboardShareTable::getById($id)->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	public function getByToken(string $token): ?SupersetDashboardShare
	{
		if (empty($token))
		{
			return null;
		}

		$ormModel = SupersetDashboardShareTable::getList([
			'filter' => ['=TOKEN' => $token],
			'limit' => 1,
		])->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	public function getByDashboardAndUser(int $dashboardId, int $userId): ?SupersetDashboardShare
	{
		$ormModel = SupersetDashboardShareTable::getList([
			'filter' => [
				'=DASHBOARD_ID' => $dashboardId,
				'=CREATED_BY_ID' => $userId,
			],
			'limit' => 1,
		])->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param int[] $dashboardIds
	 * @return array<int, SupersetDashboardShare> dashboard id => the user's link
	 */
	public function getByDashboardIdsAndUser(array $dashboardIds, int $userId): array
	{
		$dashboardIds = array_values(array_unique(array_map('intval', $dashboardIds)));
		if (empty($dashboardIds))
		{
			return [];
		}

		$collection = SupersetDashboardShareTable::getList([
			'filter' => [
				'@DASHBOARD_ID' => $dashboardIds,
				'=CREATED_BY_ID' => $userId,
			],
		])->fetchCollection();

		$result = [];
		foreach ($collection as $ormModel)
		{
			$result[$ormModel->getDashboardId()] = $this->mapper->convertFromOrm($ormModel);
		}

		return $result;
	}

	/**
	 * @return SupersetDashboardShare[]
	 */
	public function getByDashboardId(int $dashboardId): array
	{
		$collection = SupersetDashboardShareTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		])->fetchCollection();

		$result = [];
		foreach ($collection as $ormModel)
		{
			$result[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $result;
	}

	/**
	 * @return SupersetDashboardShare[]
	 */
	public function getActiveByCreatedById(int $userId): array
	{
		$collection = SupersetDashboardShareTable::getList([
			'filter' => [
				'=CREATED_BY_ID' => $userId,
				'=ACTIVE' => 'Y',
			],
		])->fetchCollection();

		$result = [];
		foreach ($collection as $ormModel)
		{
			$result[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $result;
	}

	public function existsByDashboardId(int $dashboardId): bool
	{
		return SupersetDashboardShareTable::getRow([
			'select' => ['ID'],
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		]) !== null;
	}

	/**
	 * @return SupersetDashboardShare[]
	 */
	public function getActiveByDashboardId(int $dashboardId): array
	{
		$collection = SupersetDashboardShareTable::getList([
			'filter' => [
				'=DASHBOARD_ID' => $dashboardId,
				'=ACTIVE' => 'Y',
			],
		])->fetchCollection();

		$result = [];
		foreach ($collection as $ormModel)
		{
			$result[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $result;
	}

	/**
	 * Locking read of the active links: until the transaction ends they can be neither switched off
	 * nor, where the database locks the index range, joined by a new link of the dashboard.
	 *
	 * @return SupersetDashboardShare[]
	 */
	public function lockActiveByDashboardId(int $dashboardId): array
	{
		$connection = Application::getConnection();
		$rows = $connection->query(
			(new SqlExpression(
				'SELECT ?# FROM ?# WHERE ?# = ?i AND ?# = ?s FOR UPDATE',
				'ID',
				SupersetDashboardShareTable::getTableName(),
				'DASHBOARD_ID',
				$dashboardId,
				'ACTIVE',
				'Y',
			))->compile()
		)->fetchAll();

		return $this->getByIds(array_map('intval', array_column($rows, 'ID')));
	}

	/**
	 * @return int Number of links that were on and got switched off.
	 */
	public function deactivateActiveByDashboardId(int $dashboardId, ?int $disabledById, DateTime $now): int
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = SupersetDashboardShareTable::getTableName();

		[$update] = $helper->prepareUpdate($tableName, $this->getDeactivationFields($disabledById, $now));

		$connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update
			. (new SqlExpression(' WHERE ?# = ?i AND ?# = ?s', 'DASHBOARD_ID', $dashboardId, 'ACTIVE', 'Y'))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * Saves the author's changes of a working link only while it is still on with the same address,
	 * so a switch-off made meanwhile is not rolled back.
	 *
	 * @return bool False when the link was switched off or got another address.
	 */
	public function updateIfActive(SupersetDashboardShare $share, string $expectedToken): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = SupersetDashboardShareTable::getTableName();
		// The raw update skips ORM save modifiers, so the password is encrypted here when the field is a secret.
		$password = SupersetDashboardShareTable::getEntity()
			->getField('PASSWORD')
			->modifyValueBeforeSave($share->getPassword(), [])
		;

		[$update] = $helper->prepareUpdate($tableName, [
			'PASSWORD' => $password,
			'DATE_EXPIRE' => $share->getDateExpire(),
			'DATE_MODIFY' => $share->getDateModify(),
			'EXTERNAL_FILTER_VALUES' => $share->getExternalFilterValues(),
			'URL_PARAMETER_VALUES' => $share->getUrlParameterValues(),
		]);

		$connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update
			. (new SqlExpression(
				' WHERE ?# = ?i AND ?# = ?s AND ?# = ?s',
				'ID',
				$share->getId(),
				'ACTIVE',
				'Y',
				'TOKEN',
				$expectedToken,
			))->compile()
		);

		if ($connection->getAffectedRowsCount() > 0)
		{
			return true;
		}

		// MySQL counts only changed rows, so an update with the same values has to be told apart by a reread.
		$stored = $this->getById($share->getId());

		return $stored instanceof SupersetDashboardShare && $stored->isActive() && $stored->getToken() === $expectedToken;
	}

	/**
	 * @param int[] $ids
	 * @return SupersetDashboardShare[]
	 */
	public function getByIds(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$collection = SupersetDashboardShareTable::getList([
			'filter' => ['@ID' => $ids],
		])->fetchCollection();

		$result = [];
		foreach ($collection as $ormModel)
		{
			$result[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $result;
	}

	/**
	 * Switches the link off only if it is still on, so a concurrent switch-off keeps its author.
	 * Without a disabler (the system switch-off) DISABLED_BY_ID and DATE_DISABLED stay empty.
	 */
	public function deactivateIfActive(int $id, ?int $disabledById, DateTime $now): bool
	{
		return $this->deactivateActive([$id], $disabledById, $now) > 0;
	}

	/**
	 * @param int[] $ids
	 * @return int Number of links that were on and got switched off.
	 */
	public function deactivateActive(array $ids, ?int $disabledById, DateTime $now): int
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		if (empty($ids))
		{
			return 0;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = SupersetDashboardShareTable::getTableName();

		[$update] = $helper->prepareUpdate($tableName, $this->getDeactivationFields($disabledById, $now));

		$connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update
			. (new SqlExpression(' WHERE ?# IN (?@) AND ?# = ?s', 'ID', $ids, 'ACTIVE', 'Y'))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	public function updateLoginState(int $id, int $attempts, ?DateTime $lockedTill): void
	{
		$this->updateColumns($id, [
			'LOGIN_ATTEMPTS' => $attempts,
			'LOGIN_LOCKED_TILL' => $lockedTill,
		]);
	}

	private function getDeactivationFields(?int $disabledById, DateTime $now): array
	{
		return [
			'ACTIVE' => 'N',
			'DISABLED_BY_ID' => $disabledById,
			'DATE_DISABLED' => $disabledById === null ? null : $now,
			'DATE_MODIFY' => $now,
		];
	}

	public function touchLastOpened(int $id, DateTime $at): void
	{
		$this->updateColumns($id, ['DATE_LAST_OPENED' => $at]);
	}

	public function clearLastOpened(int $id): void
	{
		$this->updateColumns($id, ['DATE_LAST_OPENED' => null]);
	}

	/**
	 * Deletes links switched off or expired before the threshold. Links switched off before
	 * DATE_DISABLED existed are dated by DATE_MODIFY.
	 *
	 * @return int[] Ids of the deleted links.
	 */
	public function deleteRetired(DateTime $threshold, int $limit): array
	{
		$ids = $this->selectRetiredIds($threshold, $limit);
		if (empty($ids))
		{
			return [];
		}

		// The condition is repeated: a link switched on again after the selection must survive.
		SupersetDashboardShareTable::deleteByFilter(
			Query::filter()
				->whereIn('ID', $ids)
				->where($this->getRetiredFilter($threshold))
		);

		$survivedIds = array_map(
			'intval',
			array_column(
				SupersetDashboardShareTable::getList([
					'select' => ['ID'],
					'filter' => ['@ID' => $ids],
				])->fetchAll(),
				'ID',
			),
		);

		return array_values(array_diff($ids, $survivedIds));
	}

	/**
	 * @return int[]
	 */
	protected function selectRetiredIds(DateTime $threshold, int $limit): array
	{
		return array_map(
			'intval',
			array_column(
				SupersetDashboardShareTable::getList([
					'select' => ['ID'],
					'filter' => $this->getRetiredFilter($threshold),
					'order' => ['ID' => 'ASC'],
					'limit' => $limit,
				])->fetchAll(),
				'ID',
			),
		);
	}

	private function getRetiredFilter(DateTime $threshold): ConditionTree
	{
		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->where(
				Query::filter()
					->where('ACTIVE', 'N')
					->where('DATE_DISABLED', '<', $threshold)
			)
			->where(
				Query::filter()
					->where('ACTIVE', 'N')
					->whereNull('DATE_DISABLED')
					->where('DATE_MODIFY', '<', $threshold)
			)
			->where(
				Query::filter()
					->where('ACTIVE', 'Y')
					->where('DATE_EXPIRE', '<', $threshold)
			)
		;
	}

	private function updateColumns(int $id, array $fields): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = SupersetDashboardShareTable::getTableName();

		[$update] = $helper->prepareUpdate($tableName, $fields);

		$connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName) . ' SET ' . $update
			. (new SqlExpression(' WHERE ?# = ?i', 'ID', $id))->compile()
		);
	}

	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof SupersetDashboardShare)
		{
			throw new PersistenceException('Entity must be an instance of SupersetDashboardShare');
		}

		try
		{
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if ($result->isSuccess() && !$entity->getId())
		{
			$entity->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save dashboard share', errors: $result->getErrorMessages()
			);
		}
	}

	public function delete(mixed $id): void
	{
		try
		{
			$result = SupersetDashboardShareTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete dashboard share', errors: $result->getErrorMessages()
			);
		}
	}

	public function deleteByDashboardId(int $dashboardId): void
	{
		SupersetDashboardShareTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);
	}
}
