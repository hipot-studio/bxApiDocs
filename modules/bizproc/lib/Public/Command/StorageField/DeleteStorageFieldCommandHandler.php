<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Bizproc\Internal\Exception\StorageField\DeleteStorageFieldException;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;

class DeleteStorageFieldCommandHandler
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function __invoke(DeleteStorageFieldCommand $command): void
	{
		$existStorageField = $this->repository->exists($command->id);
		if (!$existStorageField)
		{
			throw new DeleteStorageFieldException('Storage field not found');
		}

		$this->repository->delete($command->id);
	}
}
