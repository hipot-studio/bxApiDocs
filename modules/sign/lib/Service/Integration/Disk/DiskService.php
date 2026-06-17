<?php

namespace Bitrix\Sign\Service\Integration\Disk;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Command\UnifiedLink\SetAccessCommand;

use Bitrix\Sign\Item;
use Bitrix\Sign\Util\Filename;

class DiskService
{
	private const MODULE_ID = 'sign';
	private const ENTITY_ID = 'sign_b2e_document';

	public function isAvailable(): bool
	{
		return Main\Loader::includeModule('disk');
	}

	public function createEditableFileFromCFile(int $cfileId, int $userId, ?string $overrideName = null): Result
	{
		$result = new Result();

		if (!$this->isAvailable())
		{
			return $result->addError(new Error('The disk module is not available'));
		}

		if ($cfileId < 1)
		{
			return $result->addError(new Error('Invalid CFile id'));
		}

		if ($userId < 1)
		{
			return $result->addError(new Error('Invalid user id'));
		}

		$storage = $this->getHiddenStorage();
		if (!$storage)
		{
			return $result->addError(new Error('Failed to get hidden storage'));
		}

		$clonedFileId = \CFile::CloneFile($cfileId);
		if (!$clonedFileId)
		{
			return $result->addError(new Error('Failed to clone source file'));
		}

		$cfileArray = \CFile::GetFileArray($clonedFileId);
		if (!$cfileArray)
		{
			return $result->addError(new Error('Failed to get cloned file data'));
		}

		$folder = $storage->getRootObject();
		if (!$folder)
		{
			return $result->addError(new Error('Storage root folder not found'));
		}

		$defaultName = $cfileArray['ORIGINAL_NAME'] ?: $cfileArray['FILE_NAME'];
		$diskFile = $folder->addFile([
			'NAME' => Filename::compose($defaultName, $overrideName),
			'FILE_ID' => $clonedFileId,
			'SIZE' => $cfileArray['FILE_SIZE'],
			'CREATED_BY' => $userId,
		], [], true);

		if (!$diskFile)
		{
			return $result->addError(new Error('Failed to create disk file'));
		}

		$result->setData(['diskFile' => $diskFile]);

		return $result;
	}

	public function getEditUrl(Item\Blank $blank, int $userId, ?string $overrideName = null): Main\Result
	{
		$result = new Main\Result();

		if (!$blank->fileCollection || $blank->fileCollection->count() === 0)
		{
			return $result->addError(new Main\Error('Blank has no files'));
		}

		$sourceFile = $blank->fileCollection->first();
		if ($sourceFile === null || $sourceFile->id === null)
		{
			return $result->addError(new Main\Error('Blank source file not found'));
		}

		if (!$this->isAvailable())
		{
			return $result->addError(new Main\Error('The disk module is not available'));
		}

		$createResult = $this->createEditableFileFromCFile($sourceFile->id, $userId, $overrideName);
		if (!$createResult->isSuccess())
		{
			return $createResult;
		}

		$diskFile = $createResult->getData()['diskFile'];

		$setAccessResult = $this->setDiskFileAccessLevel($diskFile->getId(), UnifiedLinkAccessLevel::Denied);
		if (!$setAccessResult->isSuccess())
		{
			$this->deleteDiskFile($diskFile, $userId);

			return $result->addErrors($setAccessResult->getErrors());
		}

		if (!$diskFile->supportsUnifiedLink())
		{
			$this->deleteDiskFile($diskFile, $userId);

			return $result->addError(new Main\Error(Loc::getMessage('SIGN_INTEGRATION_DISK_EDITOR_NOT_CONFIGURED')));
		}

		$editUrl = $this->getUnifiedEditLinkForDiskFile($diskFile);
		if (!$editUrl)
		{
			$this->deleteDiskFile($diskFile, $userId);

			return $result->addError(new Main\Error('Failed to get edit unified link'));
		}

		$result->setData(['editUrl' => $editUrl, 'diskFileId' => $diskFile->getId()]);

		return $result;
	}

	public function getValidatedDiskFileById(int $diskFileId, int $userId): Result
	{
		$result = new Result();

		if (!$this->isAvailable())
		{
			return $result->addError(new Error('The disk module is not available'));
		}

		$diskFile = \Bitrix\Disk\File::loadById($diskFileId);
		if (!$diskFile)
		{
			return $result->addError(new Error(Loc::getMessage('SIGN_INTEGRATION_DISK_FILE_NOT_FOUND')));
		}

		$validateResult = $this->validateDiskFile($diskFile, $userId);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		return $result->setData(['diskFile' => $diskFile]);
	}

	public function deleteDiskFile(\Bitrix\Disk\File $diskFile, int $userId): Result
	{
		$validateResult = $this->validateDiskFile($diskFile, $userId);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		return $this->doDeleteDiskFile($diskFile, $userId);
	}

	public function deleteDiskFileById(int $diskFileId, int $userId): Result
	{
		$validateResult = $this->getValidatedDiskFileById($diskFileId, $userId);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		/** @var \Bitrix\Disk\File $diskFile */
		$diskFile = $validateResult->getData()['diskFile'];

		return $this->doDeleteDiskFile($diskFile, $userId);
	}

	protected function doDeleteDiskFile(\Bitrix\Disk\File $diskFile, int $userId): Result
	{
		$result = new Result();

		$deleteResult = $diskFile->delete($userId);
		if (!$deleteResult)
		{
			return $result->addError(new Error('Failed to delete disk file'));
		}

		return $result;
	}

	private function validateDiskFile(\Bitrix\Disk\File $diskFile, int $userId): Result
	{
		$result = new Result();

		$storage = $diskFile->getStorage();
		if (!$storage || $storage->getEntityId() !== self::ENTITY_ID)
		{
			return $result->addError(new Error('Disk file does not belong to sign storage'));
		}

		if ((int)$diskFile->getCreatedBy() !== $userId)
		{
			return $result->addError(new Error('Disk file does not belong to user'));
		}

		return $result;
	}

	protected function setDiskFileAccessLevel(int $objectId, UnifiedLinkAccessLevel $level): Main\Result
	{
		return (new SetAccessCommand($objectId, $level))->run();
	}

	protected function getUnifiedEditLinkForDiskFile(\Bitrix\Disk\File $diskFile): string
	{
		$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();

		return $urlManager->getUnifiedEditLink($diskFile, [
			'absolute' => true,
			'additionalQueryParams' => [
				'signPlaceholders' => 'Y',
			],
		]);
	}

	private function getHiddenStorage(): ?\Bitrix\Disk\Storage
	{
		$driver = \Bitrix\Disk\Driver::getInstance();

		$storage = $driver->addStorageIfNotExist([
			'NAME' => Loc::getMessage('SIGN_INTEGRATION_DISK_STORAGE_NAME'),
			'MODULE_ID' => self::MODULE_ID,
			'ENTITY_TYPE' => ProxyType::class,
			'ENTITY_ID' => self::ENTITY_ID,
			'USE_INTERNAL_RIGHTS' => false,
		]);

		if ($storage && $storage->isEnabledTransformation())
		{
			$storage->disableTransformation();
		}

		return $storage;
	}
}
