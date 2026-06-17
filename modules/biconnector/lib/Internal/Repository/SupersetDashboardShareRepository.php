<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardShareMapper;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;

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
