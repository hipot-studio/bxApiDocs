<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Update\Files;

use Bitrix\Disk\File;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Message;

/**
 * Brings the files an edit adds to the state the sending path leaves them in: in the folder of this chat and with the
 * params of the messenger. A file picked on Disk is copied there and takes a new identifier, so the requested
 * composition is rewritten to the identifiers of the chat folder. The copies belong to this call until the edit is
 * saved and are removed by rollback(); a file uploaded by the client is not ours and is never removed here.
 */
class AddedFilesPreparer
{
	private const CHAT_FILE_PREFIX = 'upload';
	private const DISK_FILE_PREFIX = 'disk';

	/** @var File[] */
	private array $createdFiles = [];

	/** @var int[] */
	private array $unpreparedFileIds = [];

	private int $createdByUserId = 0;

	/**
	 * A file the chat did not take keeps the identifier the client sent and is remembered as unprepared: otherwise the
	 * validation would meet it as a file of a foreign chat and would name a reason of the refusal of its own.
	 *
	 * @param int[] $requestedFileIds
	 * @param int[] $previousFileIds
	 * @return int[] the composition rewritten to the identifiers of the files in the chat folder
	 */
	public function prepare(Message $message, array $requestedFileIds, array $previousFileIds): array
	{
		$this->unpreparedFileIds = [];

		$addedFileIds = array_values(array_diff($requestedFileIds, $previousFileIds));
		if (empty($addedFileIds))
		{
			return $requestedFileIds;
		}

		$chatId = (int)$message->getChatId();
		$userId = $message->getContext()->getUserId();
		$prefixedIds = $this->prefixAddedFiles($addedFileIds, $chatId, $message->getChat()->getDiskFolderId());

		$prepared = \CIMDisk::prepareFilesInChat($chatId, array_values($prefixedIds), ['USER_ID' => $userId]);
		if (!is_array($prepared))
		{
			$this->unpreparedFileIds = $addedFileIds;

			return $requestedFileIds;
		}

		$this->createdByUserId = $userId;
		$preparedIds = $this->mapPreparedFiles($prefixedIds, $prepared);
		$this->unpreparedFileIds = array_values(array_diff($addedFileIds, array_keys($preparedIds)));

		return $this->rewriteComposition($requestedFileIds, $preparedIds);
	}

	/**
	 * Added files the chat did not take: a refusal of the preparation itself, not a file of a foreign chat.
	 *
	 * @return int[]
	 */
	public function getUnpreparedFileIds(): array
	{
		return $this->unpreparedFileIds;
	}

	/**
	 * Removes what this call created and nothing else; the list empties, so a repeated call does nothing.
	 */
	public function rollback(): void
	{
		foreach ($this->createdFiles as $file)
		{
			$file->delete($this->createdByUserId);
		}

		$this->createdFiles = [];
	}

	/**
	 * A file already in the folder of this chat keeps its identifier and gains no copy, the way a file uploaded by the
	 * client does; everything else is brought into the folder from Disk.
	 *
	 * @param int[] $addedFileIds
	 * @return array<int, string> prefixed identifier by the requested one
	 */
	private function prefixAddedFiles(array $addedFileIds, int $chatId, ?int $chatFolderId): array
	{
		$parentIds = $this->getParentFolderIds($addedFileIds, $chatId);
		$prefixedIds = [];

		foreach ($addedFileIds as $fileId)
		{
			$isInChatFolder = $chatFolderId !== null && ($parentIds[$fileId] ?? null) === $chatFolderId;
			$prefixedIds[$fileId] = ($isInChatFolder ? self::CHAT_FILE_PREFIX : self::DISK_FILE_PREFIX) . $fileId;
		}

		return $prefixedIds;
	}

	/**
	 * @param array<int, string> $prefixedIds
	 * @return array<int, int> identifier in the chat folder by the requested one
	 */
	private function mapPreparedFiles(array $prefixedIds, array $prepared): array
	{
		$preparedIds = [];

		foreach ($prefixedIds as $fileId => $prefixedId)
		{
			$fileModel = $prepared['FILE_MODELS'][$prefixedId] ?? null;
			if (!$fileModel instanceof File)
			{
				continue;
			}

			$preparedIds[$fileId] = (int)$fileModel->getId();
			if ($preparedIds[$fileId] !== $fileId)
			{
				$this->createdFiles[] = $fileModel;
			}
		}

		return $preparedIds;
	}

	/**
	 * @param int[] $requestedFileIds
	 * @param array<int, int> $preparedIds
	 * @return int[]
	 */
	private function rewriteComposition(array $requestedFileIds, array $preparedIds): array
	{
		$composition = [];

		foreach ($requestedFileIds as $fileId)
		{
			$composition[] = $preparedIds[$fileId] ?? $fileId;
		}

		return $composition;
	}

	/**
	 * @param int[] $fileIds
	 * @return array<int, int>
	 */
	private function getParentFolderIds(array $fileIds, int $chatId): array
	{
		$parentIds = [];

		foreach (FileCollection::initByDiskFilesIds($fileIds, $chatId) as $file)
		{
			$diskFile = $file->getDiskFile();
			if ($diskFile !== null)
			{
				$parentIds[$file->getId()] = (int)$diskFile->getParentId();
			}
		}

		return $parentIds;
	}
}
