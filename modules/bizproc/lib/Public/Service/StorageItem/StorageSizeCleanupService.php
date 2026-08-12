<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\StorageItem;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Service\Storage\StorageLimitsService;
use Bitrix\Bizproc\Public\Command\StorageItem\DeleteStorageItemCommand;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;

class StorageSizeCleanupService
{
	private StorageItemProvider $provider;
	private StorageLimitsService $limitsService;

	public function __construct()
	{
		$this->provider = new StorageItemProvider(0);
		$this->limitsService = Container::getStorageLimitsService();
	}

	/**
	 * Cleans up the oldest storage items when the storage tables exceed the configured size limit.
	 *
	 * Cleanup is skipped when the `storage_items_cleanup_size` option is not set (<= 0),
	 * or when the current storage tables size is below the limit.
	 *
	 * Otherwise a single batch of up to `$limit` oldest items (ordered by CREATED_TIME ASC) is
	 * deleted via DeleteStorageItemCommand and the number of deleted items is returned. Deleting
	 * a single batch per invocation keeps a run from over-deleting when the tables only slightly
	 * exceed the limit. Iterating until the limit is satisfied is the caller's responsibility:
	 * a Stepper agent re-invokes the service while it keeps returning a full batch, re-reading
	 * the storage size on every pass (mirrors StorageCleanupService / StorageCleanupAgent).
	 *
	 * @param int $limit Maximum number of items deleted per invocation.
	 * @return int Number of items deleted during the invocation.
	 */
	public function cleanupOldStorageItemsBySize(int $limit = 100): int
	{
		$maxBytes = $this->limitsService->getStorageItemsCleanupSizeBytes();
		if ($maxBytes <= 0)
		{
			return 0;
		}

		if ($this->limitsService->getStorageTablesSizeBytes() < $maxBytes)
		{
			return 0;
		}

		try
		{
			$ids = $this->provider->findOldestStorageItemIds($limit);
			if (empty($ids))
			{
				return 0;
			}

			(new DeleteStorageItemCommand($ids))->run();

			return count($ids);
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}
}
