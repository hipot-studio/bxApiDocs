<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\DataView\RowIdentity;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldCodeService;
use Bitrix\Bizproc\Internal\Exception\StorageItem\UpdateStorageItemException;
use Bitrix\Bizproc\Public\Feature\DataView\DataViewFlag;
use Bitrix\Main\Config\Feature;

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
			->setStorageId($command->storageItem->getStorageId())
			->setUpdatedBy($command->updatedBy)
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

		$dataViewRepository = $this->resolveDataViewRepository($command->storageTypeId);
		if ($dataViewRepository === null)
		{
			return $this->saveItem($command->storageTypeId, $storageItem);
		}

		// {@see \Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepository::readDataViewOwnedRows()}

		if (!$dataViewRepository->acquireViewLock($command->storageTypeId))
		{

			throw new UpdateStorageItemException(
				sprintf('Unable to acquire data view lock for storage type %d.', $command->storageTypeId),
			);
		}

		try
		{
			if ($this->isDataViewOwnedRow((int)$command->storageItem->getId()))
			{
				$storageItem->setWorkflowId(RowIdentity::WORKFLOW_FROZEN);
			}

			return $this->saveItem($command->storageTypeId, $storageItem);
		}
		finally
		{
			$dataViewRepository->releaseViewLock($command->storageTypeId);
		}
	}

	private function saveItem(int $storageTypeId, StorageItem $storageItem): StorageItem
	{
		$result = $this->repository->saveItem($storageTypeId, $storageItem)->getData();

		$storageItem->setUpdatedAt($result['UPDATED_TIME']->getTimestamp());

		return $storageItem;
	}

	private function resolveDataViewRepository(int $storageTypeId): ?DataViewRepositoryInterface
	{

		if (!Feature::isEnabled(DataViewFlag::class))
		{
			return null;
		}

		$dataViewRepository = Container::getDataViewRepository();
		if ($dataViewRepository === null || $dataViewRepository->getByStorageTypeId($storageTypeId) === null)
		{
			return null;
		}

		return $dataViewRepository;
	}

	private function isDataViewOwnedRow(int $recordId): bool
	{
		$dataManager = Container::getStorageRecordDataManager();
		if ($dataManager === null)
		{
			return false;
		}

		$row = $dataManager::getList([
			'select' => ['WORKFLOW_ID', 'CODE'],
			'filter' => ['=ID' => $recordId],
			'limit' => 1,
		])->fetch();

		if (!$row)
		{
			return false;
		}

		$isOwnedWorkflow = in_array(
			(string)$row['WORKFLOW_ID'],
			[RowIdentity::WORKFLOW_SYSTEM, RowIdentity::WORKFLOW_FROZEN],
			true,
		);

		return $isOwnedWorkflow && RowIdentity::fromSerialized($row['CODE']) !== null;
	}
}
