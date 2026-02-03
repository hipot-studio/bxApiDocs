<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Document\Models\EO_RestrictionLog;
use Bitrix\Disk\Document\Models\RestrictionLogTable;
use Bitrix\Disk\Internal\Entity\DocumentRestrictionLog;
use Bitrix\Disk\Internal\Repository\Interface\DocumentRestrictionLogRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Mapper\DocumentRestrictionLogMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Throwable;

class BitrixOrmDocumentRestrictionLogRepository implements DocumentRestrictionLogRepositoryInterface
{
	/**
	 * @param DocumentRestrictionLogMapper $mapper
	 */
	public function __construct(
		protected readonly DocumentRestrictionLogMapper $mapper,
	)
	{
	}

	/**
	 * @param int $id
	 * @return DocumentRestrictionLog|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?DocumentRestrictionLog
	{
		/** @var EO_RestrictionLog|null $ormModel */
		$ormModel = RestrictionLogTable::getById($id)->fetchObject();

		if (!$ormModel instanceof EO_RestrictionLog)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * {@inheritDoc}
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByHash(string $hash): ?DocumentRestrictionLog
	{
		/** @var EO_RestrictionLog|null $ormModel */
		$ormModel =
			RestrictionLogTable
				::query()
				->where('EXTERNAL_HASH', '=', $hash)
				->fetchObject()
		;

		if (!$ormModel instanceof EO_RestrictionLog)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * {@inheritDoc}
	 * @param DocumentRestrictionLog $entity
	 */
	public function save(EntityInterface $entity): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception->getPrevious(),
			);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				message: 'Unable to save document restriction log',
				errors: $result->getErrorMessages(),
			);
		}

		if (!is_int($entity->getId()))
		{
			$entity->setId($result->getId());
		}
	}

	/**
	 * {@inheritDoc}
	 * @param int $id
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = RestrictionLogTable::delete($id);
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				message: 'Unable to delete document restriction log',
				errors: $result->getErrorMessages(),
			);
		}
	}
}