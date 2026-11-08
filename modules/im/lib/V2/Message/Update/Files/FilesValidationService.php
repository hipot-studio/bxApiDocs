<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Update\Files;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;

class FilesValidationService
{
	/** Files allowed in one message. Source of truth for web and mobile clients. */
	public const MAX_FILES_COUNT = 10;

	/**
	 * Rejections the requested composition earns on its own: none of them needs a model of a file, so they run before
	 * the preparation copies anything into the chat.
	 *
	 * @param int[] $requestedFileIds
	 * @param int[] $previousFileIds
	 */
	public function validateRequest(Message $message, array $requestedFileIds, array $previousFileIds): Result
	{
		$result = $this->checkSendPermission($message, $requestedFileIds, $previousFileIds);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->rejectByMessageType($message);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->checkBlockFiles($message, $requestedFileIds);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $this->checkCount($requestedFileIds, $previousFileIds);
	}

	/**
	 * Guard of the composition already brought into the chat: it reads the files themselves, so it belongs after the
	 * preparation. The models it read travel on in the result - every step after it works on those very models.
	 *
	 * @param int[] $requestedFileIds
	 * @param int[] $unpreparedFileIds added files the chat did not take
	 * @return Result<FileCollection> files of the composition in the requested order
	 */
	public function validatePrepared(Message $message, array $requestedFileIds, array $unpreparedFileIds = []): Result
	{
		return $this->checkFilesAvailability($message, $requestedFileIds, $unpreparedFileIds);
	}

	/**
	 * An edit that adds a file puts it into the chat the way sending does, so it asks for the right to send. An edit
	 * that only drops or rearranges files brings nothing into the chat and needs no such right.
	 *
	 * @param int[] $requestedFileIds
	 * @param int[] $previousFileIds
	 */
	protected function checkSendPermission(Message $message, array $requestedFileIds, array $previousFileIds): Result
	{
		$result = new Result();

		if (empty(array_diff($requestedFileIds, $previousFileIds)))
		{
			return $result;
		}

		$chat = $message->getChat()->withContext($message->getContext());
		if (!$chat->canDo(Action::Send))
		{
			return $result->addError(new FileError(FileError::SEND_ACCESS_DENIED));
		}

		return $result;
	}

	protected function rejectByMessageType(Message $message): Result
	{
		$result = new Result();

		if ($message->isVoiceNote() || $message->isVideoNote())
		{
			return $result->addError(new FileError(FileError::NOT_EDITABLE_MESSAGE_TYPE));
		}

		return $result;
	}

	/**
	 * Files of a structured block are owned by the blocks builder and never travel through the composition.
	 *
	 * @param int[] $requestedFileIds
	 */
	protected function checkBlockFiles(Message $message, array $requestedFileIds): Result
	{
		$result = new Result();

		$blockFileIds = $message->getBlocksBuilder()?->getFiles() ?? [];
		if (empty($blockFileIds))
		{
			return $result;
		}

		$blockFileIds = array_map('intval', $blockFileIds);
		$requestedBlockFileIds = array_values(array_intersect($requestedFileIds, $blockFileIds));
		if (!empty($requestedBlockFileIds))
		{
			return $result->addError(new FileError(FileError::BLOCK_FILE, ['FILE_ID' => $requestedBlockFileIds[0]]));
		}

		return $result;
	}

	/**
	 * @param int[] $requestedFileIds
	 * @param int[] $previousFileIds
	 */
	protected function checkCount(array $requestedFileIds, array $previousFileIds): Result
	{
		$result = new Result();

		$requestedCount = count($requestedFileIds);
		if ($requestedCount <= self::MAX_FILES_COUNT || $requestedCount <= count($previousFileIds))
		{
			return $result;
		}

		return $result->addError(new FileError(FileError::LIMIT_EXCEEDED, ['LIMIT' => self::MAX_FILES_COUNT]));
	}

	/**
	 * A file the chat did not take and a file of a foreign chat both end up outside the folder of this chat, so the
	 * list of the unprepared ones is what tells them apart and is asked first. Every refusal here names the file it
	 * stopped at: the phrase asks to take the file out of the attachments, and out of ten of them the client can point
	 * at the right one only by the identifier.
	 *
	 * @param int[] $requestedFileIds
	 * @param int[] $unpreparedFileIds
	 * @return Result<FileCollection>
	 */
	protected function checkFilesAvailability(
		Message $message,
		array $requestedFileIds,
		array $unpreparedFileIds = [],
	): Result
	{
		$result = new Result();
		$composition = new FileCollection();

		if (empty($requestedFileIds))
		{
			return $result->setResult($composition);
		}

		$chatFolderId = $message->getChat()->getDiskFolderId();
		$userId = $message->getContext()->getUserId();
		$files = $this->getReadableFilesByIds($requestedFileIds, $message->getChatId(), $userId);

		foreach ($requestedFileIds as $fileId)
		{
			$file = $files[$fileId] ?? null;
			if ($file === null)
			{
				return $result->addError(new FileError(FileError::ACCESS_DENIED, ['FILE_ID' => $fileId]));
			}

			if (in_array($fileId, $unpreparedFileIds, true))
			{
				return $result->addError(new FileError(FileError::PREPARE_FAILED, ['FILE_ID' => $fileId]));
			}

			if ($chatFolderId === null || (int)$file->getDiskFile()?->getParentId() !== $chatFolderId)
			{
				return $result->addError(new FileError(FileError::WRONG_CHAT, ['FILE_ID' => $fileId]));
			}

			$composition[] = $file;
		}

		return $result->setResult($composition);
	}

	/**
	 * Files the user is allowed to read, by their identifiers: the right is asked of the select itself, so the whole
	 * composition costs one query and a file the user may not read simply does not come back.
	 *
	 * @param int[] $fileIds
	 * @return array<int, FileItem>
	 */
	protected function getReadableFilesByIds(array $fileIds, ?int $chatId, int $userId): array
	{
		$files = [];

		foreach (new FileCollection($this->loadReadableDiskFiles($fileIds, $userId), $chatId) as $file)
		{
			$files[$file->getId()] = $file;
		}

		return $files;
	}

	/**
	 * @param int[] $fileIds
	 * @return File[]
	 */
	protected function loadReadableDiskFiles(array $fileIds, int $userId): array
	{
		if (!Loader::includeModule('disk'))
		{
			return [];
		}

		$parameters = [
			'filter' => Query::filter()->whereIn('ID', $fileIds)->where('TYPE', FileTable::TYPE),
			'with' => ['PREVIEW'],
		];
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			$parameters,
			['ID', 'CREATED_BY'],
		);

		return File::getModelList($parameters);
	}
}
