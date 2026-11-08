<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Update\Files;

use Bitrix\Im\V2\Entity;
use Bitrix\Im\V2\Link;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;

/**
 * Brings the file links of a saved message to its composition by delta: only links of dropped files are removed
 * and only added files get a link, so rows of untouched attachments keep their place in the sidebar.
 */
class FileLinksSynchronizer
{
	/**
	 * @param int[] $requestedFileIds
	 */
	public function sync(Message $message, array $requestedFileIds): Result
	{
		$result = new Result();
		$fileService = new Link\File\FileService();

		$linkedFileIds = $this->getLinkedFileIds($message);
		$fileIdsToUnlink = array_values(array_diff($linkedFileIds, $requestedFileIds));
		$fileIdsToLink = array_values(array_diff($requestedFileIds, $linkedFileIds));

		if (!empty($fileIdsToUnlink))
		{
			$unlinkResult = $fileService->deleteFilesByDiskFileIds($fileIdsToUnlink, $message);
			if (!$unlinkResult->isSuccess())
			{
				return $result->addErrors($unlinkResult->getErrors());
			}
		}

		if (empty($fileIdsToLink))
		{
			return $result;
		}

		// until the migration of links is over the links layer forbids writing, such a portal only loses links
		if (!$fileService->isMigrationFinished())
		{
			return $result;
		}

		$diskFiles = Entity\File\FileCollection::initByDiskFilesIds($fileIdsToLink, $message->getChatId())
			->getDiskFiles()
		;

		return $result->addErrors($fileService->saveFilesFromMessage($diskFiles, $message)->getErrors());
	}

	/**
	 * Files of the actual rows of the links storage: a file of the composition may have no link at all,
	 * and the same pair may repeat.
	 *
	 * @return int[]
	 */
	private function getLinkedFileIds(Message $message): array
	{
		$linkedFileIds = Link\File\FileCollection::getByMessageIds([$message->getId()])->getEntityIds();

		return array_values(array_unique(array_map('intval', $linkedFileIds)));
	}
}
