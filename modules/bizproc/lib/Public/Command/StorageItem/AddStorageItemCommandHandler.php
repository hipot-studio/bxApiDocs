<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldCodeService;

class AddStorageItemCommandHandler
{
	private StorageItemRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageItemRepository();
	}

	public function __invoke(AddStorageItemCommand $command): StorageItem
	{
		$storageItem = new StorageItem();

		$storageItem
			->setStorageId($command->storageTypeId)
			->setCreatedBy($command->createdBy)
			->setUpdatedBy($command->createdBy)
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

		$result = $this->repository->saveItem($command->storageTypeId, $storageItem)->getData();

		$storageItem
			->setCreatedAt($result['CREATED_TIME']->getTimestamp())
			->setUpdatedAt($result['UPDATED_TIME']->getTimestamp())
			->setCode($result['CODE'])
		;

		return $storageItem;
	}
}
