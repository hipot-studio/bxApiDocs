<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Disk\Driver;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\Mobile\Dto\InvalidDtoException;

class DiskFileProvider
{
	public function __construct(private ?int $userId = null)
	{
		$this->userId = ($userId ?? CurrentUser::get()->getId());
	}

	/**
	 * @param array $fileIds
	 * @return array
	 */
	public function getDiskFileAttachments(array $fileIds): array
	{
		Collection::normalizeArrayValuesByInt($fileIds, false);

		if (!Loader::includeModule('disk') || empty($fileIds))
		{
			return [];
		}

		$driver = Driver::getInstance();
		$urlManager = $driver->getUrlManager();
		$userFieldManager = $driver->getUserFieldManager();
		$userFieldManager->loadBatchAttachedObject($fileIds);

		$diskFileAttachments = [];
		foreach ($fileIds as $fileId)
		{
			$attachedObject = $userFieldManager->getAttachedObjectById($fileId);
			if (!$attachedObject || !$attachedObject->canRead($this->userId))
			{
				continue;
			}

			$file = $attachedObject->getFile();
			if (!$file)
			{
				continue;
			}

			$diskFileAttachments[$fileId] = [
				'ID' => $fileId,
				'OBJECT_ID' => $attachedObject->getObjectId(),
				'NAME' => $file->getName(),
				'TYPE' => TypeFile::getMimeTypeByFilename($file->getName()),
				'URL' => $urlManager::getUrlUfController('show', ['attachedId' => $fileId]),
				'PREVIEW_URL' => $urlManager::getUrlToActionShowUfFile($fileId, ['width' => 640, 'height' => 640]),
				'WIDTH' => $attachedObject->getExtra()->get('FILE_WIDTH'),
				'HEIGHT' => $attachedObject->getExtra()->get('FILE_HEIGHT'),
			];
		}

		return $diskFileAttachments;
	}

	/**
	 * @param array $fileIds
	 * @return array
	 * @throws InvalidDtoException
	 */
	public function getDiskFileAttachmentsWithDto(array $fileIds): array
	{
		$diskFileAttachments = $this->getDiskFileAttachments($fileIds);
		if (empty($diskFileAttachments))
		{
			return [];
		}

		$result = [];
		foreach ($diskFileAttachments as $attachment)
		{
			$result[] = DiskFileDto::make($attachment);
		}

		return $result;
	}
}
