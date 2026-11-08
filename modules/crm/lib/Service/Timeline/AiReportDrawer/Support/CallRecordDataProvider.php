<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Integration\StorageManager;
use Bitrix\Crm\Service\Timeline\Config;
use Bitrix\Main\NotSupportedException;

final class CallRecordDataProvider
{
	/**
	 * @return array{recordId: string, recordSrc: string}|null
	 */
	public function load(array $activity): ?array
	{
		$activityId = (int)($activity['ID'] ?? 0);
		$storageTypeId = (int)($activity['STORAGE_TYPE_ID'] ?? 0);
		$elementIds = unserialize((string)($activity['STORAGE_ELEMENT_IDS'] ?? ''), ['allowed_classes' => false]);
		$elementId = is_array($elementIds) ? (int)($elementIds[0] ?? 0) : 0;

		if ($activityId <= 0 || $elementId <= 0 || !$this->isOwnedByActivity($activityId, $storageTypeId, $elementId))
		{
			return null;
		}

		try
		{
			$fileInfo = StorageManager::getFileInfo(
				$elementId,
				$storageTypeId,
				true,
				[
					'OWNER_ID' => $activityId,
					'OWNER_TYPE_ID' => \CCrmOwnerType::Activity,
				],
			);
		}
		catch (NotSupportedException)
		{
			return null;
		}

		if (!is_array($fileInfo))
		{
			return null;
		}

		$recordSrc = $fileInfo['VIEW_URL'] ?? null;
		if (
			!is_scalar($recordSrc)
			|| !in_array(
				GetFileExtension(mb_strtolower((string)($fileInfo['NAME'] ?? ''))),
				Config::ALLOWED_AUDIO_EXTENSIONS,
				true,
			)
		)
		{
			return null;
		}

		return [
			'recordId' => mb_substr((string)($activity['ORIGIN_ID'] ?? ''), 3),
			'recordSrc' => (string)$recordSrc,
		];
	}

	private function isOwnedByActivity(int $activityId, int $storageTypeId, int $elementId): bool
	{
		return \CCrmActivity::CheckStorageElementExists($activityId, $storageTypeId, $elementId);
	}
}
