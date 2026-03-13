<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Path;

use Bitrix\Im\V2\Cache\CacheConfig;

final class CachePathManager
{
	private const PARTITIONING_BASE = 100;

	public function getCachePath(int|string|null $entityId, CacheConfig $config): ?CachePath
	{
		$id = $this->generateId($entityId, $config);
		$dir = $this->generateDir($entityId, $config);

		return new CachePath(id: $id, dir: $dir);
	}

	private function generateId(int|string|null $entityId, CacheConfig $config): string
	{
		if ($entityId === null)
		{
			return $config->entityType;
		}

		return str_replace('/', '_', $config->entityType) . '_' . $entityId;
	}

	private function generateDir(int|string|null $entityId, CacheConfig $config): string
	{
		$pathParts = [$config->baseDir, $config->domain, 'v' . $config->version];

		if ($entityId === null)
		{
			return implode('/', $pathParts);
		}

		$pathParts[] = str_replace('_', '', $config->entityType);

		$partitioningPart = $this->getPartitioningPart($entityId, $config->partitioningLevels);
		if (!empty($partitioningPart))
		{
			$pathParts[] = $partitioningPart;
		}

		$pathParts[] = $entityId;

		return implode('/', $pathParts);
	}

	private function getPartitioningPart(int|string $entityId, int $partitioningLevel): ?string
	{
		if (!is_numeric($entityId))
		{
			return null;
		}

		$entityId = (int)$entityId;
		$partitioningParts = [];

		for ($i = 1; $i <= $partitioningLevel; $i++)
		{
			$partitioningParts[] = (string)(($entityId % (self::PARTITIONING_BASE ** $i)) / self::PARTITIONING_BASE ** ($i - 1));
		}

		return implode('/', $partitioningParts);
	}
}
