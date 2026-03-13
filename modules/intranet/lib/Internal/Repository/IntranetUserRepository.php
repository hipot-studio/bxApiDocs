<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Intranet\Internal\Entity\IntranetUser;
use Bitrix\Intranet\Internal\Model\EO_IntranetUser;
use Bitrix\Intranet\Internal\Model\IntranetUserTable;
use Bitrix\Intranet\Internal\Repository\Mapper\IntranetUserMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\SystemException;
use Exception;

class IntranetUserRepository implements RepositoryInterface
{
	public function __construct(private readonly IntranetUserMapper $mapper)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?IntranetUser
	{
		$ormModel = IntranetUserTable::query()
			->setSelect(['*'])
			->where('ID', '=', $id)
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByUserId(int $userId): ?IntranetUser
	{
		$ormModel = IntranetUserTable::query()
			->setSelect(['*'])
			->where('USER_ID', '=', $userId)
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getInitializedUsers(): EntityCollection
	{
		$query = IntranetUserTable::query()
			->setSelect(['*'])
			->where('INITIALIZED', '=', 'Y')
		;

		return $this->buildResultCollection($query);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getNotInitializedUsers(): EntityCollection
	{
		$query = IntranetUserTable::query()
			->setSelect(['*'])
			->where('INITIALIZED', '=', 'N')
		;

		return $this->buildResultCollection($query);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function buildResultCollection(Query $query): EntityCollection
	{
		$queryResult = $query->exec();

		$collection = new EntityCollection();

		while ($ormModel = $queryResult->fetchObject())
		{
			/** @var EO_IntranetUser $ormModel */
			$collection->add($this->mapper->convertFromOrm($ormModel));
		}

		return $collection;
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		try
		{
			/** @var IntranetUser $entity */
			$ormEntity = $this->mapper->convertToOrm($entity);
			$result = $ormEntity->save();

			if (!$result->isSuccess())
			{
				throw new PersistenceException('Unable to save intranet user', null, $result->getErrors());
			}

			if (!$entity->getId())
			{
				$entity->setId($ormEntity->getId());
			}
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			IntranetUserTable::delete($id);
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function deleteByUserId(mixed $id): void
	{
		try
		{
			IntranetUserTable::deleteByFilter(['USER_ID' => $id]);
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}
}
