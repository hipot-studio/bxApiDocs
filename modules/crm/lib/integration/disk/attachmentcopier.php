<?php

namespace Bitrix\Crm\Integration\Disk;

use Bitrix\Crm\Service\Container;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\SystemUser;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class AttachmentCopier
{
	private int $entityTypeId;
	private int $entityId;
	private int $userId;

	public function __construct(int $entityTypeId, int $entityId, int $userId)
	{
		$this->entityTypeId = $entityTypeId;
		$this->entityId = $entityId;
		$this->userId = $userId;
	}

	public function copy(array $attachedIds): Result
	{
		$result = new Result();
		$map = [];
		$createdFileIds = [];
		$result->setData([
			'map' => $map,
			'createdFileIds' => $createdFileIds,
		]);

		if (empty($attachedIds))
		{
			return $result;
		}

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('"disk" module is required.'));
		}

		try
		{
			$folder = (new HiddenStorage())
				->setUserId($this->userId)
				->setSecurityContextOptions([
					'entityTypeId' => $this->entityTypeId,
					'entityId' => $this->entityId,
				])
				->getOrCreateFolder(HiddenStorage::FOLDER_CODE_ACTIVITY)
			;

			if (!$folder)
			{
				return $result->addError(new Error('Unable to resolve hidden storage folder for attachments.'));
			}

			foreach ($attachedIds as $attachedId)
			{
				$attachedId = (int)$attachedId;

				$attachedObject = AttachedObject::loadById($attachedId);
				if (!$attachedObject)
				{
					$result->addError(new Error("Attached object {$attachedId} was not found.", $attachedId));

					continue;
				}

				if (!$attachedObject->canRead($this->userId))
				{
					$result->addError(new Error("Access to attached object {$attachedId} is denied.", $attachedId));

					continue;
				}

				$newFile = $this->copyAttachedObject($attachedObject, $folder);
				if (!$newFile instanceof File)
				{
					$result->addError(new Error("Unable to copy attached object {$attachedId}.", $attachedId));

					continue;
				}

				$createdFileIds[] = $newFile->getId();
				$map[$attachedId] = FileUserType::NEW_FILE_PREFIX . $newFile->getId();
			}

		}
		catch (\Throwable $exception)
		{
			Container::getInstance()->getLogger('Default')->error(
				'{method}: unable to copy comment attachments',
				[
					'method' => __METHOD__,
					'exceptionClass' => get_class($exception),
					'exceptionCode' => $exception->getCode(),
					'exceptionFile' => $exception->getFile(),
					'exceptionLine' => $exception->getLine(),
				],
			);
			$result->addError(new Error('Could not copy comment attachments'));
		}

		return $result->setData([
			'map' => $map,
			'createdFileIds' => $createdFileIds,
		]);
	}

	public function deleteCopies(array $fileIds): Result
	{
		$result = new Result();

		if (empty($fileIds))
		{
			return $result;
		}

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new Error('"disk" module is required.'));
		}

		$failedFileIds = [];

		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;

			$file = File::getById($fileId);
			if (!$file)
			{
				continue;
			}

			if (!$file->getStorage()->getProxyType() instanceof ProxyType)
			{
				continue;
			}

			if (!$file->delete(SystemUser::SYSTEM_USER_ID))
			{
				$failedFileIds[] = $fileId;
				Container::getInstance()->getLogger('Default')->error(
					'{method}: unable to delete copied file {fileId}',
					[
						'method' => __METHOD__,
						'fileId' => $fileId,
					],
				);
			}
		}

		foreach ($failedFileIds as $failedFileId)
		{
			$result->addError(new Error("Unable to delete copied file {$failedFileId}.", $failedFileId));
		}

		return $result->setData(['failedFileIds' => $failedFileIds]);
	}

	private function copyAttachedObject(AttachedObject $attachedObject, Folder $folder): ?File
	{
		if ($attachedObject->isSpecificVersion())
		{
			$version = $attachedObject->getVersion();

			return $version ? $version->createNewFile($folder, $this->userId, true) : null;
		}

		$file = $attachedObject->getFile();

		return $file ? $file->copyTo($folder, $this->userId, true) : null;
	}
}
