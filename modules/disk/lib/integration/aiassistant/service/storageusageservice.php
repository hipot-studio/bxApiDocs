<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\VolumeTable;
use Bitrix\Disk\Storage;
use Bitrix\Disk\User;
use Bitrix\Disk\Volume;
use Bitrix\Disk\Volume\FreshnessPolicy;
use Bitrix\Disk\Volume\Task;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Type\DateTime;

final class StorageUsageService
{
	private const MAX_LIMIT = 50;

	public function __construct(
		private readonly FreshnessPolicy $freshnessPolicy = new FreshnessPolicy(),
		private readonly SupportedStoragePolicy $supportedStoragePolicy = new SupportedStoragePolicy(),
	)
	{
	}

	public function getUsage(
		int $userId,
		string $usageType,
		?int $storageId,
		int $limit,
	): array
	{
		if (!in_array($usageType, ['summary', 'by_users', 'by_folders', 'by_storages'], true))
		{
			throw new McpException("Unknown usage type '{$usageType}'.");
		}
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		[$storage, $scopeStorageId, $isAdmin] = $this->resolveScope($userId, $usageType, $storageId);
		$indicatorType = $this->getIndicatorType($usageType);
		$freshness = $this->freshnessPolicy->evaluate($userId, $scopeStorageId, $indicatorType);
		$scope = [
			'type' => $scopeStorageId === null ? 'portal' : 'storage',
			'storageId' => $scopeStorageId,
			'admin' => $isAdmin,
		];
		if ($freshness['status'] === 'unavailable')
		{
			return [
				'usageType' => $usageType,
				'scope' => $scope,
				'generatedAt' => null,
				'freshness' => 'unavailable',
				'items' => [],
				'summary' => null,
				'resultMeta' => $this->buildResultMeta('unavailable'),
			];
		}

		$summaryRow = null;
		$rows = [];
		if ($usageType === 'summary')
		{
			$summaryRow = $this->loadSnapshotSummary(
				$userId,
				$scopeStorageId,
				$indicatorType,
				(string)$freshness['generatedAt'],
			);
		}
		else
		{
			$rows = $this->loadSnapshotRows(
				$userId,
				$scopeStorageId,
				$indicatorType,
				(string)$freshness['generatedAt'],
				$limit + 1,
			);
		}
		if (
			($usageType === 'summary' && $summaryRow === null)
			|| ($usageType !== 'summary' && !$rows)
		)
		{
			return [
				'usageType' => $usageType,
				'scope' => $scope,
				'generatedAt' => null,
				'freshness' => 'unavailable',
				'items' => [],
				'summary' => null,
				'resultMeta' => $this->buildResultMeta('unavailable'),
			];
		}

		$hasMore = $usageType !== 'summary' && count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);
		$limitations = [];
		$summary = null;
		$items = [];
		if ($usageType === 'summary')
		{
			$summary = $this->buildSummary((int)$summaryRow['USED_BYTES'], $storage, $limitations);
		}
		else
		{
			$items = array_map(
				fn(array $row): array => $this->projectRow($row, $usageType),
				$rows,
			);
		}

		return [
			'usageType' => $usageType,
			'scope' => $scope,
			'generatedAt' => $freshness['generatedAt'],
			'freshness' => $freshness['status'],
			'items' => $items,
			'summary' => $summary,
			'hasMore' => $hasMore,
			'resultMeta' => $this->buildResultMeta($freshness['status'], $hasMore, $limitations),
		];
	}

	private function resolveScope(int $userId, string $usageType, ?int $storageId): array
	{
		$user = User::loadById($userId);
		$isAdmin = $user?->isAdmin() === true;
		if (!$isAdmin)
		{
			if (in_array($usageType, ['by_users', 'by_storages'], true))
			{
				throw new McpException("Access denied for usage type '{$usageType}'.");
			}
			$personalStorage = Driver::getInstance()->getStorageByUserId($userId);
			if (!$personalStorage)
			{
				throw new McpException('Personal Disk storage is not available.');
			}
			if ($storageId !== null && $storageId !== (int)$personalStorage->getId())
			{
				throw new McpException('Ordinary users can request usage only for their personal Disk.');
			}
			$this->ensureSupportedStorage($personalStorage);

			return [$personalStorage, (int)$personalStorage->getId(), false];
		}

		if ($usageType === 'by_folders' && $storageId === null)
		{
			throw new McpException("usage type '{$usageType}' requires storageId.");
		}
		if (in_array($usageType, ['by_users', 'by_storages'], true) && $storageId !== null)
		{
			throw new McpException("usage type '{$usageType}' requires portal scope without storageId.");
		}
		$storage = null;
		if ($storageId !== null)
		{
			$storage = Storage::loadById($storageId);
			if (!$storage)
			{
				throw new McpException("Storage {$storageId} not found.");
			}
			$this->ensureSupportedStorage($storage);
		}

		return [$storage, $storageId, true];
	}

	private function ensureSupportedStorage(Storage $storage): void
	{
		if (!$this->supportedStoragePolicy->supports($storage))
		{
			throw new McpException("Storage {$storage->getId()} is not supported by get_storage_usage.");
		}
	}

	private function getIndicatorType(string $usageType): string
	{
		return match ($usageType)
		{
			'by_folders' => Volume\Folder::class,
			'by_users' => Volume\Storage\User::class,
			default => Volume\Storage\Storage::class,
		};
	}

	private function loadSnapshotRows(
		int $ownerId,
		?int $storageId,
		string $indicatorType,
		string $generatedAt,
		int $limit,
	): array
	{
		return VolumeTable::getList([
			'select' => [
				'FILE_SIZE',
				'PREVIEW_SIZE',
				'FILE_COUNT',
				'TITLE',
				'STORAGE_ID',
				'FOLDER_ID',
				'USER_ID',
				'ENTITY_ID',
				'ENTITY_TYPE',
			],
			'filter' => $this->buildSnapshotFilter($ownerId, $storageId, $indicatorType, $generatedAt),
			'order' => ['FILE_SIZE' => 'DESC', 'ID' => 'DESC'],
			'limit' => $limit,
		])->fetchAll();
	}

	private function loadSnapshotSummary(
		int $ownerId,
		?int $storageId,
		string $indicatorType,
		string $generatedAt,
	): ?array
	{
		$row = VolumeTable::getRow([
			'select' => ['ROWS_COUNT', 'USED_BYTES'],
			'filter' => $this->buildSnapshotFilter($ownerId, $storageId, $indicatorType, $generatedAt),
			'runtime' => [
				new ExpressionField('ROWS_COUNT', 'COUNT(%s)', 'ID'),
				new ExpressionField(
					'USED_BYTES',
					'COALESCE(SUM(%s), 0) + COALESCE(SUM(%s), 0)',
					['FILE_SIZE', 'PREVIEW_SIZE'],
				),
			],
		]);

		return (int)($row['ROWS_COUNT'] ?? 0) > 0 ? $row : null;
	}

	private function buildSnapshotFilter(
		int $ownerId,
		?int $storageId,
		string $indicatorType,
		string $generatedAt,
	): array
	{
		$filter = [
			'=OWNER_ID' => $ownerId,
			'=INDICATOR_TYPE' => $indicatorType,
			'=AGENT_LOCK' => Task::TASK_STATUS_NONE,
			'=CREATE_TIME' => DateTime::createFromTimestamp(strtotime($generatedAt)),
		];
		if ($storageId !== null)
		{
			$filter['=STORAGE_ID'] = $storageId;
		}

		return $filter;
	}

	private function buildSummary(int $usedBytes, ?Storage $storage, array &$limitations): array
	{
		$totalBytes = Volume\Storage\Storage::getAvailableSpace($storage);
		if ($totalBytes <= 0)
		{
			$totalBytes = null;
			$limitations[] = ['code' => 'STORAGE_LIMIT_UNAVAILABLE'];
		}
		$freeBytes = $totalBytes === null ? null : max(0, $totalBytes - $usedBytes);
		$percent = $totalBytes === null ? null : round($usedBytes * 100 / $totalBytes, 1);

		return [
			'usedBytes' => $usedBytes,
			'used' => \CFile::formatSize($usedBytes),
			'totalBytes' => $totalBytes,
			'total' => $totalBytes === null ? null : \CFile::formatSize($totalBytes),
			'freeBytes' => $freeBytes,
			'free' => $freeBytes === null ? null : \CFile::formatSize($freeBytes),
			'percent' => $percent,
			'limitUnavailableReason' => $totalBytes === null ? 'No active storage limit is available.' : null,
		];
	}

	private function projectRow(array $row, string $usageType): array
	{
		$usedBytes = (int)$row['FILE_SIZE'] + (int)($row['PREVIEW_SIZE'] ?? 0);
		$id = match ($usageType)
		{
			'by_folders' => $row['FOLDER_ID'],
			'by_users' => $row['USER_ID'],
			'by_storages' => $row['STORAGE_ID'],
			default => $row['ENTITY_ID'],
		};

		return [
			'id' => $id === null ? null : (int)$id,
			'name' => $row['TITLE'] ?: null,
			'usedBytes' => $usedBytes,
			'used' => \CFile::formatSize($usedBytes),
			'fileCount' => (int)$row['FILE_COUNT'],
			'storageId' => $row['STORAGE_ID'] === null ? null : (int)$row['STORAGE_ID'],
			'folderId' => $row['FOLDER_ID'] === null ? null : (int)$row['FOLDER_ID'],
			'userId' => !isset($row['USER_ID']) ? null : (int)$row['USER_ID'],
			'entityType' => $row['ENTITY_TYPE'] ?: null,
		];
	}

	private function buildResultMeta(
		string $status,
		bool $hasMore = false,
		array $limitations = [],
	): array
	{
		return [
			'status' => $status,
			'partial' => $hasMore || $limitations !== [],
			'hasMore' => $hasMore,
			'limitations' => $limitations,
		];
	}
}
