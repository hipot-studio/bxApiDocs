<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Exception\StorageField\UpdateStorageFieldException;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;

class UpdateStorageFieldCommandHandler
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function __invoke(UpdateStorageFieldCommand $command): StorageField
	{
		$storageField = $command->storageField;
		$existStorageField = $this->repository->exists((int)$storageField->getId());
		if (!$existStorageField)
		{
			throw new UpdateStorageFieldException('Storage field not found');
		}

		$this->repository->save($storageField);

		return $storageField;
	}
}
