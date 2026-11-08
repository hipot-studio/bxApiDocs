<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Update\Files;

use Bitrix\Disk\File;
use Bitrix\Disk\SystemUser;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Error;

/**
 * Decides the fate of every file an edit dropped from the composition, one file at a time: a file the author uploaded
 * into the folder of this chat leaves Disk for good, any other file stays on Disk and only loses its link. Links of
 * the edited message are gone by now, and other messages sharing a removed file are closed by the handler of Disk.
 *
 * @see FileLinksSynchronizer
 * @see \CIMDisk::OnAfterDeleteFile
 */
class DroppedFilesService
{
	private const LOG_TAG = 'message_update_dropped_file_not_deleted';

	/**
	 * A rejected deletion is not an error of the edit: the file stays on Disk, as a file of somebody else does, and its
	 * link is removed either way. Only the log keeps a trace.
	 *
	 * @param int[] $droppedFileIds
	 */
	public function handle(Message $message, array $droppedFileIds): void
	{
		$chatFolderId = $message->getChat()->getDiskFolderId();
		if (empty($droppedFileIds) || $chatFolderId === null)
		{
			return;
		}

		// the delivered scope lets only the author edit the composition, so the caller stands for the author here
		$authorId = $message->getContext()->getUserId();

		foreach (FileCollection::initByDiskFilesIds($droppedFileIds, $message->getChatId()) as $file)
		{
			$diskFile = $file->getDiskFile();
			if ($diskFile === null)
			{
				continue;
			}

			if ((int)$diskFile->getCreatedBy() !== $authorId || (int)$diskFile->getParentId() !== $chatFolderId)
			{
				continue;
			}

			if (!$diskFile->delete(SystemUser::SYSTEM_USER_ID))
			{
				$this->logRejectedDeletion($message, $diskFile);
			}
		}
	}

	/**
	 * The edit still answers success, so the log is the only trace of a file left in the folder of the chat without a
	 * composition and without a link. Disk leaves its error collection empty on some branches, hence the identifiers.
	 */
	private function logRejectedDeletion(Message $message, File $diskFile): void
	{
		(new Logger(self::LOG_TAG))->logArray([
			'messageId' => $message->getMessageId(),
			'diskFileId' => $diskFile->getId(),
			'errors' => array_map(
				static fn(Error $error): string => $error->getCode() . ': ' . $error->getMessage(),
				$diskFile->getErrors(),
			),
		]);
	}
}
