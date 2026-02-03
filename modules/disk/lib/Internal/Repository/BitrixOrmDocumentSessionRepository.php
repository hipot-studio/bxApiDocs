<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Models\EO_DocumentSession;
use Bitrix\Disk\Internal\Entity\DocumentSession;
use Bitrix\Disk\Internal\Entity\DocumentSessionCollection;
use Bitrix\Disk\Internal\Repository\Interface\DocumentSessionRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Mapper\DocumentSessionMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Throwable;

class BitrixOrmDocumentSessionRepository implements DocumentSessionRepositoryInterface
{
	/**
	 * @param DocumentSessionMapper $mapper
	 */
	public function __construct(
		protected readonly DocumentSessionMapper $mapper,
	)
	{
	}

	/**
	 * @param int $id
	 * @return DocumentSession|null
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?DocumentSession
	{
		/** @var EO_DocumentSession|null $ormModel */
		$ormModel = DocumentSessionTable::getById($id)->fetchObject();

		if (!$ormModel instanceof EO_DocumentSession)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * {@inheritDoc}
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getOnlyOfficeForDrop(DateTime $after = null): DocumentSessionCollection
	{
		$ormCollection =
			DocumentSessionTable
                ::query()
                ->setSelect([
                    'ID',
                    'SERVICE',
                    'USER_ID',
                    'EXTERNAL_HASH',
                ])
                ->where('SERVICE', '=', DocumentService::OnlyOffice->value)
                ->where('TYPE', '=', DocumentSessionTable::TYPE_EDIT)
				->where('STATUS', '=', DocumentSessionTable::STATUS_ACTIVE)
				->where('CREATE_TIME', '<', $after)
                ->exec()
                ->fetchCollection()
		;

		$collection = new DocumentSessionCollection();

		foreach ($ormCollection as $documentSession)
		{
			$collection->add($this->mapper->convertFromOrm($documentSession));
		}

		return $collection;
	}

	/**
	 * {@inheritDoc}
	 * @param DocumentSession $entity
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
				message: 'Unable to save document session',
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
			$result = DocumentSessionTable::delete($id);
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
				message: 'Unable to delete document session',
				errors: $result->getErrorMessages(),
			);
		}
	}
}