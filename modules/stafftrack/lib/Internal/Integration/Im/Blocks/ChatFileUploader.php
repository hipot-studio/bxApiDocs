<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

use Bitrix\Disk\File;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;

final class ChatFileUploader
{
	private const LOG_PREFIX = 'StaffTrack check-in attachment copy failed: ';
	private const DISCARD_LOG_PREFIX = 'StaffTrack check-in attachment copy discard failed: ';

	public function __construct(private readonly Chat $chat)
	{
	}

	/**
	 * @param list<int> $fileIds
	 * @param array<int, File> $diskFilesById
	 * @return array<int, int>
	 */
	public function upload(array $fileIds, int $authorId, array $diskFilesById): array
	{
		if ($fileIds === [])
		{
			return [];
		}

		if (!Loader::includeModule('disk'))
		{
			$this->log(0, 'diskModuleUnavailable');

			return [];
		}

		$copies = [];

		foreach ($fileIds as $fileId)
		{
			$diskFile = $diskFilesById[$fileId] ?? null;
			if ($diskFile === null)
			{
				$this->log($fileId, 'diskFileNotFound');

				continue;
			}

			if (!FileResolver::isAccessible($diskFile, $authorId))
			{
				$this->log($fileId, 'accessDenied');

				continue;
			}

			$copyId = $this->copyToChat($diskFile, $authorId);
			if ($copyId !== null)
			{
				$copies[$fileId] = $copyId;
			}
		}

		return $copies;
	}

	/**
	 * @param list<int> $copyIds
	 */
	public function discard(array $copyIds, int $userId): void
	{
		if ($copyIds === [])
		{
			return;
		}

		if (!Loader::includeModule('disk'))
		{
			$this->log(0, 'diskModuleUnavailable', self::DISCARD_LOG_PREFIX);

			return;
		}

		foreach ($copyIds as $copyId)
		{
			$this->discardCopy((int)$copyId, $userId);
		}
	}

	private function discardCopy(int $copyId, int $userId): void
	{
		try
		{
			$copy = File::loadById($copyId);
			if (!($copy instanceof File))
			{
				$this->log($copyId, 'copyNotFound', self::DISCARD_LOG_PREFIX);

				return;
			}

			if (!$copy->delete($userId))
			{
				$this->log($copyId, 'deleteFailed', self::DISCARD_LOG_PREFIX);
			}
		}
		catch (\Throwable $exception)
		{
			$this->log($copyId, $exception::class, self::DISCARD_LOG_PREFIX);
		}
	}

	private function copyToChat(File $diskFile, int $authorId): ?int
	{
		$fileId = (int)$diskFile->getId();

		try
		{
			$copy = \CIMDisk::SaveFromLocalDisk((int)$this->chat->getChatId(), $fileId, false, $authorId);
			if (!($copy instanceof File))
			{
				$this->log($fileId, 'copyFailed');

				return null;
			}

			$copyId = (int)$copy->getId();
			if ($copyId <= 0)
			{
				$this->log($fileId, 'emptyCopyId');

				return null;
			}

			return $copyId;
		}
		catch (\Throwable $exception)
		{
			$this->log($fileId, $exception::class);

			return null;
		}
	}

	private function log(int $fileId, string $reason, string $prefix = self::LOG_PREFIX): void
	{
		\AddMessage2Log(
			$prefix . 'chatId=' . (int)$this->chat->getId() . ', fileId=' . $fileId . ', reason=' . $reason,
		);
	}
}
