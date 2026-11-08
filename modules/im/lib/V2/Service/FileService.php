<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Service;

use Bitrix\Disk;
use Bitrix\Disk\Controller\Response\PreviewResponseBuilder;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\DownloadType;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Entity\File\PreviewSource;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class FileService
{
	/** Maximum decoded file size in bytes (100 MB). */
	private const MAX_FILE_SIZE = 100 * 1024 * 1024;

	/** Repeats the cache time \Bitrix\Disk\Controller\Response\PreviewResponseBuilder sets for a resized image. */
	private const PREVIEW_CACHE_TIME = 86400;

	public function uploadFileToChat(
		Chat $chat,
		string $fileName,
		string $base64Content,
		int $actorUserId,
		?string $messageText = null,
	): Result
	{
		$uploadResult = $this->uploadFileToChatFolder($chat, $fileName, $base64Content, $actorUserId);
		if (!$uploadResult->isSuccess())
		{
			return $uploadResult;
		}

		$result = new Result();
		$chatId = $chat->getId();
		$fileId = (int)$uploadResult->getData()['fileId'];
		$messageFields = [
			'CHAT_ID' => $chatId,
			'FROM_USER_ID' => $actorUserId,
			'SKIP_USER_CHECK' => 'Y',
			'PARAMS' => ['FILE_ID' => [$fileId]],
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

		$fileItem = FileItem::initByDiskFileId($fileId, $chatId);

		$result->setData([
			'file' => $fileItem?->toRestFormat(),
			'messageId' => $messageId,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		]);

		return $result;
	}

	public function uploadFileToChatFolder(
		Chat $chat,
		string $fileName,
		string $base64Content,
		int $actorUserId,
	): Result
	{
		if (empty($fileName) || empty($base64Content))
		{
			return (new Result())->addError(new Error('File name and content are required', 'FILE_EMPTY'));
		}

		$maxBase64Length = (int)(self::MAX_FILE_SIZE * 4 / 3);
		if (mb_strlen($base64Content, '8bit') > $maxBase64Length)
		{
			return (new Result())->addError(new Error('File size exceeds maximum allowed', 'FILE_TOO_LARGE'));
		}

		$decodedContent = base64_decode($base64Content, true);
		if ($decodedContent === false)
		{
			return (new Result())->addError(new Error('Invalid base64 content', 'FILE_INVALID_CONTENT'));
		}

		$uploadResult = $this->uploadContentToChatFolder($chat, $fileName, $decodedContent, $actorUserId);
		if ($uploadResult->isSuccess())
		{
			$uploadResult->setData(['fileId' => (int)$uploadResult->getData()['fileId']]);
		}

		return $uploadResult;
	}

	public function uploadContentToChatFolder(
		Chat $chat,
		string $fileName,
		string $content,
		int $actorUserId,
		bool $registerContentVersion = true,
	): Result
	{
		$result = new Result();

		if ($fileName === '' || $content === '')
		{
			return $result->addError(new Error('File name and content are required', 'FILE_EMPTY'));
		}

		if (strlen($content) > self::MAX_FILE_SIZE)
		{
			return $result->addError(new Error('File size exceeds maximum allowed', 'FILE_TOO_LARGE'));
		}

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('Disk module not available', 'FILE_UPLOAD_FAILED'));
		}

		$folder = \CIMDisk::GetFolderModel($chat->getId());
		if (!$folder)
		{
			return $result->addError(new Error('Could not get chat folder', 'FILE_FOLDER_ERROR'));
		}

		$fileModel = $folder->uploadFile(
			['name' => $fileName, 'content' => $content],
			['CREATED_BY' => $actorUserId],
			[],
			true,
		);

		if (!$fileModel)
		{
			return $result->addError(new Error('File upload failed', 'FILE_UPLOAD_FAILED'));
		}

		if ($registerContentVersion)
		{
			$fileModel->increaseGlobalContentVersion();
		}

		return $result->setData([
			'fileId' => (int)$fileModel->getId(),
			'fileModel' => $fileModel,
		]);
	}

	public function attachImageToChat(Chat $chat, int $fileId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('Disk module not available', 'FILE_NOT_IN_CHAT'));
		}

		$chatFolderId = $chat->getDiskFolderId();
		if ($chatFolderId === null || $chatFolderId <= 0)
		{
			return $result->addError(new Error('Chat folder not found', 'FILE_NOT_IN_CHAT'));
		}

		$file = FileItem::initByDiskFileId($fileId, $chat->getId());
		$diskFile = $file?->getDiskFile();
		if (
			$diskFile === null
			|| (int)$diskFile->getParentId() !== $chatFolderId
			|| $file->getContentType() !== 'image'
		)
		{
			return $result->addError(new Error('Image is not available in chat', 'FILE_NOT_IN_CHAT'));
		}

		$diskFile->increaseGlobalContentVersion();

		return $result->setData(['fileId' => $fileId]);
	}

	/**
	 * Builds the response that carries the file body itself. Every error has to be collected here:
	 * once the response is sent, CFile::viewByUser terminates the process.
	 *
	 * @return Result data: ['response' => Response\File]
	 */
	public function buildDirectDownloadResponse(FileItem $file, DownloadType $type): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new FileError(FileError::DISK_NOT_INSTALLED));
		}

		$diskFile = $file->getDiskFile();
		if ($diskFile === null)
		{
			return $result->addError(new FileError(FileError::NOT_FOUND));
		}

		$response = $type === DownloadType::Preview ? $this->buildPreviewResponse($file, $diskFile) : null;
		$response ??= $this->buildOriginalResponse($file, $diskFile, $type);

		if ($response === null)
		{
			return $result->addError(new FileError(FileError::NOT_FOUND));
		}

		return $result->setData(['response' => $response]);
	}

	/**
	 * Null asks the caller for the original, and both ways of getting it are legitimate: the branch of
	 * the file is the original itself, or disk found no data to build a preview from.
	 */
	private function buildPreviewResponse(FileItem $file, Disk\File $diskFile): ?Response\File
	{
		$source = $file->getPreviewSource();
		if ($source !== PreviewSource::ResizedImage && $source !== PreviewSource::GeneratedPreview)
		{
			return null;
		}

		$response = (new PreviewResponseBuilder())->createByFile(
			$diskFile,
			FileItem::MAX_PREVIEW_IMAGE_SIZE,
			FileItem::MAX_PREVIEW_IMAGE_SIZE,
			'N',
		);

		return $response instanceof Response\File ? $response : null;
	}

	private function buildOriginalResponse(FileItem $file, Disk\File $diskFile, DownloadType $type): ?Response\BFile
	{
		$fileData = \CFile::getFileArray($file->getOriginalFileId());
		if (!is_array($fileData))
		{
			return null;
		}

		$cacheTime = $type === DownloadType::Preview
			? self::PREVIEW_CACHE_TIME
			: Disk\Configuration::DEFAULT_CACHE_TIME
		;

		$response = Response\BFile::createByFileData($fileData, $diskFile->getName());
		$response->setCacheTime($cacheTime);

		return $response;
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
