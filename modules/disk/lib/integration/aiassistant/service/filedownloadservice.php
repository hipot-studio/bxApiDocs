<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

class FileDownloadService
{
	public const ERROR_DOWNLOAD_TOO_LARGE = 'DOWNLOAD_TOO_LARGE';

	private const MAX_FILE_SIZE = 50 * 1024 * 1024;
	private const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

	private readonly SupportedStoragePolicy $supportedStoragePolicy;

	public function __construct(?SupportedStoragePolicy $supportedStoragePolicy = null)
	{
		$this->supportedStoragePolicy = $supportedStoragePolicy ?? new SupportedStoragePolicy();
	}

	/**
	 * Validates the current file state and issues a short-lived download grant.
	 *
	 * @param int $userId
	 * @param int $objectId
	 * @return Result
	 */
	public function issue(int $userId, int $objectId): Result
	{
		$result = $this->resolveCurrentState($userId, $objectId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$state = $result->getData();
		$grant = FileDownloadGrant::issue($userId, $objectId);
		$result->setData([
			'id' => $objectId,
			'name' => $state['name'],
			'size' => $state['size'],
			'contentType' => $state['contentType'],
			'token' => $grant['token'],
			'expiresAt' => $grant['expiresAt'],
		]);

		return $result;
	}

	/**
	 * Opens a grant and validates the current file state again.
	 *
	 * @param string $token
	 * @return Result
	 */
	public function open(string $token): Result
	{
		$grant = FileDownloadGrant::open($token);
		if ($grant === null)
		{
			return $this->createErrorResult('The download grant is invalid.', 'INVALID_GRANT');
		}

		$result = $this->resolveCurrentState($grant['userId'], $grant['objectId']);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$state = $result->getData();
		$result->setData([
			'fileData' => $state['fileData'],
			'name' => $state['name'],
			'contentType' => $state['contentType'],
		]);

		return $result;
	}

	protected function isUserActive(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return (bool)UserTable::query()
			->setSelect(['ID'])
			->where('ID', $userId)
			->where('ACTIVE', 'Y')
			->setLimit(1)
			->fetch()
		;
	}

	protected function loadFile(int $objectId): ?File
	{
		return File::loadById($objectId);
	}

	protected function isSupportedStorage(Storage $storage): bool
	{
		return $this->supportedStoragePolicy->supports($storage);
	}

	protected function getSecurityContext(Storage $storage, int $userId): ?SecurityContext
	{
		$securityContext = $storage->getSecurityContext($userId);

		return $securityContext instanceof SecurityContext ? $securityContext : null;
	}

	protected function canDownload(SecurityContext $securityContext, int $objectId): bool
	{
		return $securityContext->canDownload($objectId);
	}

	protected function loadFileData(int $fileId): ?array
	{
		$fileData = \CFile::getFileArray($fileId);

		return is_array($fileData) ? $fileData : null;
	}

	private function resolveCurrentState(int $userId, int $objectId): Result
	{
		if (!$this->isUserActive($userId))
		{
			return $this->createErrorResult('The user is inactive.', 'USER_INACTIVE');
		}

		$file = $objectId > 0 ? $this->loadFile($objectId) : null;
		$storage = $file instanceof File ? $file->getStorage() : null;
		if (
			!$file instanceof File
			|| (int)$file->getDeletedType() !== ObjectTable::DELETED_TYPE_NONE
			|| !$storage instanceof Storage
			|| !$this->isSupportedStorage($storage)
		)
		{
			return $this->createErrorResult('The file is not available.', 'FILE_NOT_FOUND');
		}

		$securityContext = $this->getSecurityContext($storage, $userId);
		if (
			!$securityContext instanceof SecurityContext
			|| !$this->canDownload($securityContext, (int)$file->getRealObjectId())
		)
		{
			return $this->createErrorResult('Downloading the file is forbidden.', 'DOWNLOAD_FORBIDDEN');
		}

		$fileData = $this->loadFileData((int)$file->getFileId());
		if (!is_array($fileData) || !isset($fileData['FILE_SIZE']) || !is_numeric($fileData['FILE_SIZE']))
		{
			return $this->createErrorResult('The current file content is not available.', 'FILE_CONTENT_NOT_FOUND');
		}

		$fileSize = (int)$fileData['FILE_SIZE'];
		if ($fileSize < 0)
		{
			return $this->createErrorResult('The current file content is not available.', 'FILE_CONTENT_NOT_FOUND');
		}
		if ($fileSize > self::MAX_FILE_SIZE)
		{
			return $this->createErrorResult('The file exceeds the download size limit.', self::ERROR_DOWNLOAD_TOO_LARGE);
		}

		$result = new Result();
		$result->setData([
			'fileData' => $fileData,
			'name' => (string)$file->getName(),
			'size' => $fileSize,
			'contentType' => $this->normalizeContentType($fileData['CONTENT_TYPE'] ?? null),
		]);

		return $result;
	}

	private function normalizeContentType(mixed $contentType): string
	{
		if (!is_string($contentType))
		{
			return self::DEFAULT_CONTENT_TYPE;
		}

		$contentType = mb_strtolower(trim(explode(';', $contentType, 2)[0]));
		if (!preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D', $contentType))
		{
			return self::DEFAULT_CONTENT_TYPE;
		}

		return $contentType;
	}

	private function createErrorResult(string $message, string $code): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code));

		return $result;
	}
}
