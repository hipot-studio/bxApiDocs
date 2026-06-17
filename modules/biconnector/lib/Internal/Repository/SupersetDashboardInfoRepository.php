<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfo;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardInfoMapper;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;

class SupersetDashboardInfoRepository implements RepositoryInterface
{
	public function __construct(private readonly SupersetDashboardInfoMapper $mapper)
	{
	}

	/**
	 * @param mixed $id
	 * @return SupersetDashboardInfo|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = SupersetDashboardInfoTable::getById($id)->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	public function getByDashboardId(int $dashboardId): ?EntityInterface
	{
		$ormModel = SupersetDashboardInfoTable::getList(['filter' => ['=DASHBOARD_ID' => $dashboardId]])->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param EntityInterface $entity
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof SupersetDashboardInfo)
		{
			throw new PersistenceException('Entity must be an instance of DashboardInfo');
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
				'Unable to save dashboard info', errors: $result->getErrorMessages()
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
			$result = SupersetDashboardInfoTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete dashboard info', errors: $result->getErrorMessages()
			);
		}
	}
}
