<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Exception\StorageItem\DeleteStorageItemException;

class DeleteStorageItemCommandHandler
{
	private StorageItemRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageItemRepository();
	}

	public function __invoke(DeleteStorageItemCommand $command): void
	{
		if (is_array($command->id))
		{
			$this->repository->deleteByIds($command->id);

			return;
		}

		$existStorageType = $this->repository->exists($command->id);
		if (!$existStorageType)
		{
			throw new DeleteStorageItemException('Storage type not found');
		}

		$this->repository->deleteItem($command->id);
	}
}
