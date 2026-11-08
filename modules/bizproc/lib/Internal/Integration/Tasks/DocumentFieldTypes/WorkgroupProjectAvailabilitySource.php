<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\ProjectProvider;
use Bitrix\Socialnetwork\WorkgroupTable;

final class WorkgroupProjectAvailabilitySource implements ProjectAvailabilitySource
{
	private const PROJECT_STATE_BATCH_SIZE = 100;

	private array $existsCache = [];
	private array $visibleCache = [];

	public function preloadExistence(array $projectIds): void
	{
		$missingProjectIds = [];
		foreach ($projectIds as $projectId)
		{
			if (!array_key_exists($projectId, $this->existsCache))
			{
				$missingProjectIds[] = $projectId;
			}
		}

		if ($missingProjectIds === [])
		{
			return;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			foreach ($missingProjectIds as $projectId)
			{
				$this->existsCache[$projectId] = null;
			}

			return;
		}

		$existingProjectIds = [];
		foreach (array_chunk($missingProjectIds, self::PROJECT_STATE_BATCH_SIZE) as $chunk)
		{
			$result = WorkgroupTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->where('PROJECT', 'Y')
				->exec()
			;

			while ($row = $result->fetch())
			{
				$existingProjectIds[(int)$row['ID']] = true;
			}
		}

		foreach ($missingProjectIds as $projectId)
		{
			$this->existsCache[$projectId] = isset($existingProjectIds[$projectId]);
		}
	}

	public function preloadVisibility(array $projectIds, int $userId): void
	{
		$missingProjectIds = [];
		foreach ($projectIds as $projectId)
		{
			$cacheKey = $this->getVisibleCacheKey($projectId, $userId);
			if (!array_key_exists($cacheKey, $this->visibleCache))
			{
				$missingProjectIds[] = $projectId;
			}
		}

		if ($missingProjectIds === [])
		{
			return;
		}

		if (!Loader::includeModule('socialnetwork') || !Loader::includeModule('ui'))
		{
			foreach ($missingProjectIds as $projectId)
			{
				$this->visibleCache[$this->getVisibleCacheKey($projectId, $userId)] = false;
			}

			return;
		}

		foreach ($missingProjectIds as $projectId)
		{
			$this->visibleCache[$this->getVisibleCacheKey($projectId, $userId)] = false;
		}

		$projectProvider = new ProjectProvider(['currentUserId' => $userId]);
		foreach (array_chunk($missingProjectIds, self::PROJECT_STATE_BATCH_SIZE) as $chunk)
		{
			foreach ($projectProvider->getItems($chunk) as $item)
			{
				$projectId = (int)$item->getId();
				$cacheKey = $this->getVisibleCacheKey($projectId, $userId);
				if (array_key_exists($cacheKey, $this->visibleCache))
				{
					$this->visibleCache[$cacheKey] = true;
				}
			}
		}
	}

	public function projectExists(int $projectId): ?bool
	{
		$this->preloadExistence([$projectId]);

		return $this->existsCache[$projectId] ?? null;
	}

	public function projectVisible(int $projectId, int $userId): bool
	{
		$this->preloadVisibility([$projectId], $userId);

		return $this->visibleCache[$this->getVisibleCacheKey($projectId, $userId)] ?? false;
	}

	private function getVisibleCacheKey(int $projectId, int $userId): string
	{
		return $userId . ':' . $projectId;
	}
}
