<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class FileService
{
	/** Maximum decoded file size in bytes (100 MB). */
	private const MAX_FILE_SIZE = 100 * 1024 * 1024;

	public function uploadFileToChat(
		Chat $chat,
		string $fileName,
		string $base64Content,
		int $actorUserId,
		?string $messageText = null,
	): Result
	{
		$result = new Result();

		if (empty($fileName) || empty($base64Content))
		{
			return $result->addError(new Error('File name and content are required', 'FILE_EMPTY'));
		}

		$maxBase64Length = (int)(self::MAX_FILE_SIZE * 4 / 3);
		if (mb_strlen($base64Content, '8bit') > $maxBase64Length)
		{
			return $result->addError(new Error('File size exceeds maximum allowed', 'FILE_TOO_LARGE'));
		}

		$decodedContent = base64_decode($base64Content, true);
		if ($decodedContent === false)
		{
			return $result->addError(new Error('Invalid base64 content', 'FILE_INVALID_CONTENT'));
		}

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('Disk module not available', 'FILE_UPLOAD_FAILED'));
		}

		$chatId = $chat->getId();
		$folder = \CIMDisk::GetFolderModel($chatId);
		if (!$folder)
		{
			return $result->addError(new Error('Could not get chat folder', 'FILE_FOLDER_ERROR'));
		}

		$fileModel = $folder->uploadFile(
			['name' => $fileName, 'content' => $decodedContent],
			['CREATED_BY' => $actorUserId],
			[],
			true,
		);

		if (!$fileModel)
		{
			return $result->addError(new Error('File upload failed', 'FILE_UPLOAD_FAILED'));
		}

		$fileModel->increaseGlobalContentVersion();

		$messageFields = [
			'CHAT_ID' => $chatId,
			'FROM_USER_ID' => $actorUserId,
			'SKIP_USER_CHECK' => 'Y',
			'PARAMS' => ['FILE_ID' => [$fileModel->getId()]],
		];

		if (!empty($messageText))
		{
			$messageFields['MESSAGE'] = $messageText;
		}

		$messageId = \CIMMessenger::Add($messageFields);

		if (!$messageId)
		{
			return $result->addError(new Error('Failed to send file message', 'FILE_SEND_FAILED'));
		}

		$fileItem = FileItem::initByDiskFileId($fileModel->getId(), $chatId);

		$result->setData([
			'file' => $fileItem?->toRestFormat(),
			'messageId' => $messageId,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		]);

		return $result;
	}

	public function resolveDownloadUrl(
		FileItem $file,
		?\CRestServer $restServer = null,
	): Result
	{
		$result = new Result();

		$diskFile = $file->getDiskFile();
		if ($diskFile === null)
		{
			return $result->addError(new Error('File not found', 'FILE_NOT_FOUND'));
		}

		if ($restServer === null)
		{
			$result->setData(['downloadUrl' => $file->toRestFormat()['urlDownload'] ?? '']);

			return $result;
		}

		$downloadUrl = \CRestUtil::getDownloadUrl(['id' => $diskFile->getId()], $restServer);

		$result->setData(['downloadUrl' => $downloadUrl]);

		return $result;
	}
}
