<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;

class AddStorageFieldCommandHandler
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function __invoke(AddStorageFieldCommand $command): StorageField
	{
		$storageField = $command->storageField;
		$result = $this->repository->save($storageField)->getData();

		$storageField->setCode($result['CODE']);

		return $storageField;
	}
}
