<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\StorageItem;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Public\Command\StorageItem\DeleteStorageItemCommand;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Main\Type\DateTime;

class StorageCleanupService
{
	private StorageItemProvider $provider;
	private int $cleanupDays;

	public function __construct(int $cleanupDays = null)
	{
		$this->provider = new StorageItemProvider(0);
		$this->cleanupDays = $cleanupDays ?? Container::getStorageLimitsService()->getCleanupDays();
	}

	public function cleanupOldStorageItems(int $limit = 100): int
	{
		$foundCount = 0;
		$cutoffDate = (new DateTime())->add('-' . $this->cleanupDays . ' days');

		try
		{
			$ids = $this->provider->findOldStorageItemIds($cutoffDate, $limit);
			$foundCount = count($ids);
			if ($foundCount > 0)
			{
				$command = new DeleteStorageItemCommand($ids);
				$command->run();
			}
		}
		catch (\Throwable $e)
		{}

		return $foundCount;
	}
}
