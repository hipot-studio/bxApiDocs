<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Exception\StorageType\DeleteStorageTypeException;
use Bitrix\Bizproc\Infrastructure\Stepper\StorageItemDeleteStepper;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

class DeleteStorageTypeCommandHandler
{
	private StorageTypeRepositoryInterface $repository;
	private StorageFieldRepositoryInterface $fieldRepository;
	private Connection $connection;

	public function __construct()
	{
		$this->connection = Application::getConnection();
		$this->repository = Container::getStorageTypeRepository();
		$this->fieldRepository = Container::getStorageFieldRepository();
	}

	public function __invoke(DeleteStorageTypeCommand $command): void
	{
		$existStorageType = $this->repository->exists($command->id);
		if (!$existStorageType)
		{
			throw new DeleteStorageTypeException('Storage type not found');
		}

		$this->connection->startTransaction();

		try
		{
			$this->fieldRepository->deleteByStorageId($command->id);
			$this->repository->delete($command->id);
		}
		catch (\Throwable $exception)
		{
			$this->connection->rollbackTransaction();
			throw new DeleteStorageTypeException($exception->getMessage());
		}

		$this->connection->commitTransaction();
		StorageItemDeleteStepper::bindStorage($command->id);
	}
}
