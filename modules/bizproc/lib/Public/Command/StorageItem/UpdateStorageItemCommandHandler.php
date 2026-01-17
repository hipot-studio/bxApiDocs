<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldCodeService;
use Bitrix\Bizproc\Internal\Exception\StorageItem\UpdateStorageItemException;

class UpdateStorageItemCommandHandler
{
	private StorageItemRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageItemRepository();
	}

	public function __invoke(UpdateStorageItemCommand $command): StorageItem
	{
		$storageItem = new StorageItem();

		$storageItem
			->setId($command->storageItem->getId())
			->setUpdatedBy($command->updatedBy)
			->setCode($command->storageItem->getCode())
			->setDocumentId($command->storageItem->getDocumentId())
			->setWorkflowId($command->storageItem->getWorkflowId())
			->setTemplateId($command->storageItem->getTemplateId())
		;

		$fieldCodes = (new FieldCodeService())->getFieldCodes($command->storageTypeId);
		if ($fieldCodes)
		{
			foreach ($fieldCodes as $code)
			{
				$storageItem->setValueField($code, $command->storageItem->getValueField($code));
			}
		}

		$existStorageItem = $this->repository->exists((int)$command->storageItem->getId());
		if (!$existStorageItem)
		{
			throw new UpdateStorageItemException('Storage item not found');
		}

		$result = $this->repository->saveItem($command->storageTypeId, $storageItem)->getData();

		$storageItem->setUpdatedAt($result['UPDATED_TIME']->getTimestamp());

		return $storageItem;
	}
}
