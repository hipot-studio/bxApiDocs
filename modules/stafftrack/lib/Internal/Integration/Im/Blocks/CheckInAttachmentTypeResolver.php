<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;

final class CheckInAttachmentTypeResolver
{
	private const LOG_PREFIX = 'StaffTrack check-in attachment skipped: ';

	/**
	 * @param array $fileIds
	 * @return list<CheckInAttachment>
	 */
	public static function resolve(array $fileIds, int $userId): array
	{
		if (empty($fileIds))
		{
			return [];
		}

		if (!Loader::includeModule('disk'))
		{
			self::log(0, $userId, 'diskModuleUnavailable');

			return [];
		}

		$ids = array_values(array_unique(array_filter(
			array_map('intval', $fileIds),
			static fn(int $id) => $id > 0,
		)));
		if ($ids === [])
		{
			return [];
		}

		$typedIds = array_slice($ids, 0, CheckInAttachmentPlanner::maxAttachments());

		$diskFilesById = self::loadDiskFiles($typedIds);

		$attachments = [];
		foreach ($ids as $id)
		{
			$diskFile = $diskFilesById[$id] ?? null;
			if ($diskFile !== null && !FileResolver::isAccessible($diskFile, $userId))
			{
				self::log($id, $userId, 'accessDenied');

				continue;
			}

			$attachments[] = new CheckInAttachment($id, $diskFile !== null && self::isMedia($diskFile), $diskFile);
		}

		return $attachments;
	}

	private static function log(int $fileId, int $userId, string $reason): void
	{
		\AddMessage2Log(
			self::LOG_PREFIX . 'userId=' . $userId . ', fileId=' . $fileId . ', reason=' . $reason,
		);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, File>
	 */
	private static function loadDiskFiles(array $ids): array
	{
		$indexed = [];

		/** @var File $diskFile */
		foreach (File::loadBatchById($ids, ['STORAGE']) as $diskFile)
		{
			$indexed[(int)$diskFile->getId()] = $diskFile;
		}

		return $indexed;
	}

	private static function isMedia(File $diskFile): bool
	{
		return in_array((int)$diskFile->getTypeFile(), [TypeFile::IMAGE, TypeFile::VIDEO], true);
	}
}
