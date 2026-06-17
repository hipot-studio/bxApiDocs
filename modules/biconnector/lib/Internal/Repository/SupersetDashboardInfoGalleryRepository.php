<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfoGallery;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoGalleryTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardInfoGalleryMapper;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\ORM\Query\Result;
use Bitrix\Main\ORM\Objectify\Collection;

class SupersetDashboardInfoGalleryRepository implements RepositoryInterface
{
	public function __construct(private readonly SupersetDashboardInfoGalleryMapper $mapper)
	{
	}

	/**
	 * @param mixed $id
	 * @return SupersetDashboardInfoGallery|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = SupersetDashboardInfoGalleryTable::getById($id)->fetchObject();

		return $ormModel ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param EntityInterface $entity
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof SupersetDashboardInfoGallery)
		{
			throw new PersistenceException('Entity must be an instance of SupersetDashboardInfoGallery');
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
				'Unable to save dashboard info gallery item', errors: $result->getErrorMessages()
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
			$result = SupersetDashboardInfoGalleryTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete dashboard info gallery item', errors: $result->getErrorMessages()
			);
		}
	}

	/**
	 * @param int $dashboardInfoId
	 * @return Collection
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByDashboardInfoId(int $dashboardInfoId): Collection
	{
		return SupersetDashboardInfoGalleryTable::getList([
			'filter' => ['=DASHBOARD_INFO_ID' => $dashboardInfoId],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchCollection();
	}
}
