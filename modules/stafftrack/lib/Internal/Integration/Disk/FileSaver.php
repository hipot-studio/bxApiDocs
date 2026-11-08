<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\Ui\Text;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Configuration;
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

	public function saveUploadedFiles(UploadedFileSet $fileSet, int $userId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('ui'))
		{
			return $result->addError(new Error('Module "ui" is not installed'));
		}

		$controller = new CheckInUploadController([]);
		$uploader = new Uploader($controller);
		$pendingFiles = $uploader->getPendingFiles($fileSet->getAllTokens());

		$attachmentFileIds = $this->resolveFileIds($pendingFiles, $fileSet->getAttachmentTokens());
		if (empty($attachmentFileIds))
		{
			return $result->setData(['fileIds' => []]);
		}

		$folderResult = $this->resolveFolder($userId);
		if (!$folderResult->isSuccess())
		{
			return $result->addErrors($folderResult->getErrors());
		}

		$folder = $folderResult->getData()['folder'];
		$diskFiles = $this->addFilesToFolder($folder, array_values($attachmentFileIds), $userId);
		$this->attachPosters(
			$fileSet,
			$pendingFiles,
			array_keys($attachmentFileIds),
			$diskFiles,
			$controller->getConfiguration(),
		);
		$this->makePersistent($pendingFiles, $fileSet->getAttachmentTokens());

		return $result->setData([
			'fileIds' => array_map(static fn(File $diskFile): int => $diskFile->getId(), array_values($diskFiles)),
		]);
	}

	/**
	 * @param string[] $tokens
	 * @return array<string, int>
	 */
	private function resolveFileIds(PendingFileCollection $pendingFiles, array $tokens): array
	{
		$fileIds = [];
		foreach ($tokens as $token)
		{
			$fileId = $pendingFiles->get($token)?->getFileId();
			if ($fileId !== null)
			{
				$fileIds[$token] = $fileId;
			}
		}

		return $fileIds;
	}

	/**
	 * @param string[] $attachmentTokens
	 * @param array<int, File> $diskFiles
	 */
	private function attachPosters(
		UploadedFileSet $fileSet,
		PendingFileCollection $pendingFiles,
		array $attachmentTokens,
		array $diskFiles,
		Configuration $configuration,
	): void
	{
		foreach ($attachmentTokens as $position => $token)
		{
			$diskFile = $diskFiles[$position] ?? null;
			$posterToken = $fileSet->getPosterTokenFor((string)$token);
			if ($diskFile === null || $posterToken === null)
			{
				continue;
			}

			if (CFile::isImage($diskFile->getName()))
			{
				continue;
			}

			$posterResult = $this->resolvePosterFileData($pendingFiles, $posterToken, $configuration);
			if (!$posterResult->isSuccess())
			{
				$this->logPosterFailure($diskFile, implode('; ', $posterResult->getErrorMessages()));

				continue;
			}

			$this->attachPoster($diskFile, $posterResult->getData()['fileData']);
		}
	}

	private function resolvePosterFileData(
		PendingFileCollection $pendingFiles,
		string $posterToken,
		Configuration $configuration,
	): Result
	{
		$result = new Result();

		$posterFileId = $pendingFiles->get($posterToken)?->getFileId();
		if ($posterFileId === null)
		{
			return $result->addError(new Error('the uploaded poster is gone'));
		}

		$posterFileData = CFile::makeFileArray($posterFileId);
		if (!is_array($posterFileData) || empty($posterFileData['tmp_name']))
		{
			return $result->addError(new Error('the uploaded poster holds no readable file'));
		}

		return $this->normalizeImageFileData($posterFileData, $configuration);
	}

	private function normalizeImageFileData(array $fileData, Configuration $configuration): Result
	{
		$result = new Result();

		$imageInfo = @getimagesize($fileData['tmp_name']);
		if (!is_array($imageInfo) || empty($imageInfo[2]))
		{
			return $result->addError(new Error('the uploaded poster holds no image'));
		}

		$extension = image_type_to_extension($imageInfo[2], false);
		$mimeType = image_type_to_mime_type($imageInfo[2]);
		if ($extension === false || !$this->isImageTypeAcceptedByPortal($extension, $mimeType))
		{
			return $result->addError(new Error('the image type of the uploaded poster is not accepted'));
		}

		if (!$this->isImageWithinUploadLimits($imageInfo, (int)($fileData['size'] ?? 0), $configuration))
		{
			return $result->addError(
				new Error('the uploaded poster is outside the image limits of the uploader'),
			);
		}

		$baseName = pathinfo((string)($fileData['name'] ?? ''), PATHINFO_FILENAME);

		$fileData['name'] = ($baseName === '' ? 'poster' : $baseName) . '.' . $extension;
		$fileData['type'] = $mimeType;

		return $result->setData(['fileData' => $fileData]);
	}

	private function isImageTypeAcceptedByPortal(string $extension, string $mimeType): bool
	{
		return CFile::isImage('poster.' . $extension, $mimeType);
	}

	private function isImageWithinUploadLimits(array $imageInfo, int $size, Configuration $configuration): bool
	{
		$imageMaxFileSize = $configuration->getImageMaxFileSize();
		if ($imageMaxFileSize !== null && $size > $imageMaxFileSize)
		{
			return false;
		}

		$width = (int)$imageInfo[0];
		$height = (int)$imageInfo[1];

		return $size >= $configuration->getImageMinFileSize()
			&& $width >= $configuration->getImageMinWidth()
			&& $height >= $configuration->getImageMinHeight()
			&& $width <= $configuration->getImageMaxWidth()
			&& $height <= $configuration->getImageMaxHeight()
		;
	}

	private function attachPoster(File $diskFile, array $posterFileData): void
	{
		try
		{
			$attachResult = $diskFile->attachImagePreview($posterFileData);
			if (!$attachResult->isSuccess())
			{
				$this->logPosterFailure($diskFile, implode('; ', $attachResult->getErrorMessages()));
			}
		}
		catch (\Throwable $e)
		{
			$this->logPosterFailure($diskFile, $e::class . ': ' . $e->getMessage());
		}
	}

	private function logPosterFailure(File $diskFile, string $reason): void
	{
		AddMessage2Log(
			'FileSaver: could not attach poster to disk file ' . $diskFile->getId() . ': ' . $reason,
			'stafftrack',
		);
	}

	/**
	 * @param string[] $attachmentTokens
	 */
	private function makePersistent(PendingFileCollection $pendingFiles, array $attachmentTokens): void
	{
		foreach ($attachmentTokens as $token)
		{
			$pendingFiles->get($token)?->makePersistent();
		}
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

	/**
	 * @param int[] $fileIds
	 * @return array<int, File>
	 */
	private function addFilesToFolder(Folder $folder, array $fileIds, int $userId): array
	{
		$diskFiles = [];
		foreach ($fileIds as $position => $fileId)
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
				$diskFiles[$position] = $diskFile;
			}
		}

		return $diskFiles;
	}
}
