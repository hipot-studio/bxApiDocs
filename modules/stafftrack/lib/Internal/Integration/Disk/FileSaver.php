<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\Ui\Text;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\Uploader;
use CFile;

class FileSaver
{
	/**
	 * @param array $fileIds
	 * @return int[]
	 */
	public static function normalizeFileIds(array $fileIds): array
	{
		return array_values(array_filter(
			array_map(static function($id): int {
				$id = (string)$id;
				if (mb_substr($id, 0, 1) === FileUserType::NEW_FILE_PREFIX)
				{
					$id = mb_substr($id, 1);
				}

				return (int)$id;
			}, $fileIds),
			static fn(int $id) => $id > 0,
		));
	}

	public function saveUploadedFiles(array $tokens, int $userId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('ui'))
		{
			return $result->addError(new Error('Module "ui" is not installed'));
		}

		$controller = new CheckInUploadController([]);
		$uploader = new Uploader($controller);
		$pendingFiles = $uploader->getPendingFiles($tokens);

		$fileIds = $pendingFiles->getFileIds();
		if (empty($fileIds))
		{
			return $result->setData(['fileIds' => []]);
		}

		$folderResult = $this->resolveFolder($userId);
		if (!$folderResult->isSuccess())
		{
			return $result->addErrors($folderResult->getErrors());
		}

		$folder = $folderResult->getData()['folder'];
		$diskFileIds = $this->addFilesToFolder($folder, $fileIds, $userId);
		$pendingFiles->makePersistent();

		return $result->setData(['fileIds' => $diskFileIds]);
	}

	private function resolveFolder(int $userId): Result
	{
		$result = new Result();

		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if (!$storage)
		{
			return $result->addError(new Error('Could not obtain user storage'));
		}

		$folder = $storage->getFolderForUploadedFiles();
		if (!$folder)
		{
			return $result->addError(new Error('Could not obtain upload folder'));
		}

		if (!$folder->canAdd($storage->getSecurityContext($userId)))
		{
			return $result->addError(new Error('Access denied'));
		}

		return $result->setData(['folder' => $folder]);
	}

	private function addFilesToFolder(Folder $folder, array $fileIds, int $userId): array
	{
		$diskFileIds = [];
		foreach ($fileIds as $fileId)
		{
			$fileArray = CFile::getFileArray($fileId);
			if (!$fileArray)
			{
				continue;
			}

			$diskFile = $folder->addFile(
				[
					'NAME' => Text::correctFilename($fileArray['ORIGINAL_NAME']),
					'FILE_ID' => $fileId,
					'CONTENT_PROVIDER' => null,
					'SIZE' => $fileArray['FILE_SIZE'],
					'CREATED_BY' => $userId,
					'UPDATE_TIME' => null,
				],
				[],
				true,
			);

			if ($diskFile)
			{
				$diskFileIds[] = $diskFile->getId();
			}
		}

		return $diskFileIds;
	}
}
