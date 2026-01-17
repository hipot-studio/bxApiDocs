<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Bizproc\Internal\Exception\StorageType\UpdateStorageTypeException;

class UpdateStorageTypeCommandHandler
{
	private StorageTypeRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageTypeRepository();
	}

	public function __invoke(UpdateStorageTypeCommand $command): StorageType
	{
		$storageType = new StorageType();

		$storageType
			->setId($command->storageType->getId())
			->setTitle($command->storageType->getTitle())
			->setDescription($command->storageType->getDescription())
			->setCode($command->storageType->getCode())
			->setUpdatedBy($command->updatedBy)
		;

		$existStorageType = $this->repository->exists((int)$command->storageType->getId());
		if (!$existStorageType)
		{
			throw new UpdateStorageTypeException('Storage type not found');
		}

		$result = $this->repository->save($storageType)->getData();

		$storageType->setUpdatedAt($result['UPDATED_TIME']->getTimestamp());

		return $storageType;
	}
}
