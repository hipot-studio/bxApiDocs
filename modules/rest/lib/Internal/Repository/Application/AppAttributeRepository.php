<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Rest\Internal\Entity\Application\AppAttribute;
use Bitrix\Rest\Internal\Model\AppAttributeTable;
use Bitrix\Rest\Internal\Repository\Mapper\AppMapper;

class AppAttributeRepository implements RepositoryInterface
{
	public function __construct(
		private readonly AppMapper $mapper = new AppMapper(),
	)
	{
	}

	public function getById(mixed $id): ?AppAttribute
	{
		$ormModel = AppAttributeTable::getById($id)->fetchObject();

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertAttributeFromOrm($ormModel);
	}

	/**
	 * @param AppAttribute $entity
	 *
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		try
		{
			$result = $this->mapper->convertAttributeToOrm($entity)->save();
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
			throw new PersistenceException('Unable to save app attribute');
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = AppAttributeTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete app attribute');
		}
	}

	/**
	 * @return AppAttribute[]
	 */
	public function getByAppId(int $appId): array
	{
		$ormCollection = AppAttributeTable::query()
			->setSelect(['*'])
			->where('APP_ID', $appId)
			->fetchCollection()
		;

		$result = [];

		foreach ($ormCollection as $ormModel)
		{
			$result[] = $this->mapper->convertAttributeFromOrm($ormModel);
		}

		return $result;
	}

	public function getByAppIdAndCode(int $appId, string $code): ?AppAttribute
	{
		$ormModel = AppAttributeTable::query()
			->setSelect(['*'])
			->where('APP_ID', $appId)
			->where('CODE', $code)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertAttributeFromOrm($ormModel);
	}

	public function deleteByAppId(int $appId): void
	{
		AppAttributeTable::deleteByAppId($appId);
	}
}
