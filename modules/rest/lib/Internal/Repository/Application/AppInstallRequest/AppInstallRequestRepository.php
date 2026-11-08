<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application\AppInstallRequest;

use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequest;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequestStatus;
use Bitrix\Rest\Internal\Model\AppInstallRequestTable;
use Bitrix\Rest\Internal\Repository\Mapper\AppInstallRequestMapper;

final class AppInstallRequestRepository
{
	public function __construct(
		private readonly AppInstallRequestMapper $mapper = new AppInstallRequestMapper(),
	)
	{
	}

	public function findActivePendingByUser(string $appCode, string $appVersion, int $userId): ?AppInstallRequest
	{
		$ormModel = AppInstallRequestTable::query()
			->setSelect(['*'])
			->where('APP_CODE', $appCode)
			->where('APP_VERSION', $appVersion)
			->where('STATUS', AppInstallRequestStatus::Pending->value)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	public function hasActivePendingByUser(string $appCode, string $appVersion, int $userId): bool
	{
		return AppInstallRequestTable::query()
			->setSelect(['ID'])
			->where('APP_CODE', $appCode)
			->where('APP_VERSION', $appVersion)
			->where('STATUS', AppInstallRequestStatus::Pending->value)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch() !== false
		;
	}

	/**
	 * @return AppInstallRequest[]
	 */
	public function findPendingGroup(string $appCode, string $appVersion): array
	{
		$collection = AppInstallRequestTable::query()
			->setSelect(['*'])
			->where('APP_CODE', $appCode)
			->where('APP_VERSION', $appVersion)
			->where('STATUS', AppInstallRequestStatus::Pending->value)
			->fetchCollection()
		;

		$result = [];
		foreach ($collection as $ormModel)
		{
			$result[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $result;
	}

	public function getById(int $id): ?AppInstallRequest
	{
		$ormModel = AppInstallRequestTable::getById($id)->fetchObject();

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(AppInstallRequest $entity): void
	{
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
			throw new PersistenceException('Unable to save AppInstallRequest');
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function deleteById(int $id): void
	{
		try
		{
			$result = AppInstallRequestTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete AppInstallRequest');
		}
	}
}
