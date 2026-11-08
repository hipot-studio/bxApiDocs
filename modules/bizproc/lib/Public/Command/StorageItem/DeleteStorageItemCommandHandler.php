<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\DataView\RowIdentity;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Exception\StorageItem\DeleteStorageItemException;
use Bitrix\Bizproc\Public\Feature\DataView\DataViewFlag;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;

class DeleteStorageItemCommandHandler
{
	private const QUERY_CHUNK = 500;

	private StorageItemRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageItemRepository();
	}

	public function __invoke(DeleteStorageItemCommand $command): void
	{
		if (!is_array($command->id))
		{
			$existStorageType = $this->repository->exists($command->id);
			if (!$existStorageType)
			{
				throw new DeleteStorageItemException('Storage type not found');
			}
		}

		$ids = is_array($command->id) ? $command->id : [$command->id];

		$deletionMarks = $this->collectDataViewDeletionMarks($ids);

		if (!$deletionMarks)
		{
			$this->deleteRecords($command);

			return;
		}

		$dataViewRepository = Container::getDataViewRepository();

		$viewByType = [];
		$identitiesByType = [];
		foreach ($deletionMarks as [$view, $identity])
		{
			$storageTypeId = $view->getStorageTypeId();
			$viewByType[$storageTypeId] = $view;
			$identitiesByType[$storageTypeId][] = $identity;
		}
		$storageTypeIds = array_keys($viewByType);

		sort($storageTypeIds);

		$acquiredLocks = [];
		foreach ($storageTypeIds as $storageTypeId)
		{
			if (!$dataViewRepository->acquireViewLock($storageTypeId))
			{
				foreach ($acquiredLocks as $acquiredId)
				{
					$dataViewRepository->releaseViewLock($acquiredId);
				}

				throw new DeleteStorageItemException(
					sprintf('Unable to acquire data view lock for storage type %d.', $storageTypeId),
				);
			}

			$acquiredLocks[] = $storageTypeId;
		}

		$connection = Application::getConnection();

		try
		{
			$connection->startTransaction();

			foreach ($storageTypeIds as $storageTypeId)
			{
				$dataViewRepository->appendDeletionMarks($viewByType[$storageTypeId], $identitiesByType[$storageTypeId]);
			}

			$this->deleteRecords($command);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e instanceof DeleteStorageItemException ? $e : new DeleteStorageItemException($e->getMessage());
		}
		finally
		{
			foreach ($acquiredLocks as $acquiredId)
			{
				$dataViewRepository->releaseViewLock($acquiredId);
			}
		}
	}

	private function deleteRecords(DeleteStorageItemCommand $command): void
	{
		if (is_array($command->id))
		{
			$this->repository->deleteByIds($command->id);

			return;
		}

		$this->repository->deleteItem($command->id);
	}

	private function collectDataViewDeletionMarks(array $ids): array
	{

		if (!Feature::isEnabled(DataViewFlag::class))
		{
			return [];
		}

		$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0));
		if (!$ids)
		{
			return [];
		}

		$dataViewRepository = Container::getDataViewRepository();
		$dataManager = Container::getStorageRecordDataManager();
		if ($dataViewRepository === null || $dataManager === null)
		{
			return [];
		}

		$viewByType = [];
		$marks = [];

		foreach (array_chunk($ids, self::QUERY_CHUNK) as $chunk)
		{
			$rows = $dataManager::getList([
				'select' => ['ID', 'STORAGE_ID', 'WORKFLOW_ID', 'CODE'],
				'filter' => ['=ID' => $chunk],
			]);
			while ($row = $rows->fetch())
			{
				$isOwnedWorkflow = in_array(
					(string)$row['WORKFLOW_ID'],
					[RowIdentity::WORKFLOW_SYSTEM, RowIdentity::WORKFLOW_FROZEN],
					true,
				);
				if (!$isOwnedWorkflow)
				{
					continue;
				}

				$identity = RowIdentity::fromSerialized($row['CODE']);
				if ($identity === null)
				{
					continue;
				}

				$storageTypeId = (int)$row['STORAGE_ID'];
				if (!array_key_exists($storageTypeId, $viewByType))
				{
					$viewByType[$storageTypeId] = $dataViewRepository->getByStorageTypeId($storageTypeId);
				}

				$view = $viewByType[$storageTypeId];
				if ($view === null)
				{
					continue;
				}

				$marks[] = [$view, $identity->toArray()];
			}
		}

		return $marks;
	}
}
