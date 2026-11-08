<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardView;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardViewTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardViewMapper;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardViewRepository implements RepositoryInterface
{
	public function __construct(private readonly SupersetDashboardViewMapper $mapper)
	{
	}

	/**
	 * @param mixed $id
	 * @return SupersetDashboardView|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = SupersetDashboardViewTable::getById($id)->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param EntityInterface $entity
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof SupersetDashboardView)
		{
			throw new PersistenceException('Entity must be an instance of DashboardView');
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
				'Unable to save dashboard view', errors: $result->getErrorMessages()
			);
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
			$result = SupersetDashboardViewTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete dashboard view', errors: $result->getErrorMessages()
			);
		}
	}

	/**
	 * @param int $dashboardId
	 * @return int
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function countViews(int $dashboardId): int
	{
		return SupersetDashboardViewTable::getCount(['=DASHBOARD_ID' => $dashboardId]);
	}

	/**
	 * The log grows with every report a user opens, so the question "is there anything of theirs
	 * from before" is answered by the first matching row, not by counting all of them.
	 *
	 * @param int $userId
	 * @param DateTime $viewedBefore
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function hasViewsBefore(int $userId, DateTime $viewedBefore): bool
	{
		return (bool)SupersetDashboardViewTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('VIEWED_AT', '<', $viewedBefore)
			->setLimit(1)
			->fetch()
		;
	}
}
