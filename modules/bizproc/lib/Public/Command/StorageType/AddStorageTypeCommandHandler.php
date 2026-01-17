<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageType;

use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Bizproc\Internal\Container;

class AddStorageTypeCommandHandler
{
	private StorageTypeRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageTypeRepository();
	}

	public function __invoke(AddStorageTypeCommand $command): StorageType
	{
		$storageType = new StorageType();

		$storageType
			->setTitle($command->storageType->getTitle())
			->setDescription($command->storageType->getDescription())
			->setCode($command->storageType->getCode())
			->setCreatedBy($command->createdBy)
			->setUpdatedBy($command->createdBy)
		;

		$result = $this->repository->save($storageType)->getData();

		$storageType
			->setCreatedAt($result['CREATED_TIME']->getTimestamp())
			->setUpdatedAt($result['UPDATED_TIME']->getTimestamp())
		;

		return $storageType;
	}
}
