<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Sign\Item;
use Bitrix\Sign\Result\Service\Sign\CreateBlankArchiveResult;
use Bitrix\Sign\Util\Request;

class BlankArchiveService
{
	private const FALLBACK_DOWNLOAD_FILENAME = 'blank';

	public function createFromBlank(Item\Blank $blank): Main\Result|CreateBlankArchiveResult
	{
		if (!class_exists(\ZipArchive::class))
		{
			return (new Main\Result())->addError(new Error('ZipArchive is not available', 'ZIP_NOT_AVAILABLE'));
		}

		if ($blank->fileCollection === null || $blank->fileCollection->count() === 0)
		{
			return (new Main\Result())->addError(new Error('Blank has no files', 'BLANK_NO_FILES'));
		}

		$zipPath = \CTempFile::GetFileName('blank_' . $blank->id . '.zip');
		$dir = Main\IO\Path::getDirectory($zipPath);
		(new Main\IO\Directory($dir))->create();

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE) !== true)
		{
			return (new Main\Result())->addError(new Error('Failed to create archive', 'ZIP_CREATE_ERROR'));
		}

		$usedNames = [];
		foreach ($blank->fileCollection as $file)
		{
			$fileArray = \CFile::MakeFileArray($file->id);
			$absPath = $fileArray['tmp_name'] ?? null;
			if (!$absPath || !Main\IO\File::isFileExists($absPath))
			{
				return $this->abortZip($zip, $zipPath, 'Blank file not found', 'BLANK_FILE_NOT_FOUND');
			}

			$entryName = $this->resolveUniqueEntryName($fileArray['name'] ?? $file->name, $usedNames);
			$usedNames[$entryName] = ($usedNames[$entryName] ?? 0) + 1;
			if (!$zip->addFile($absPath, $entryName))
			{
				return $this->abortZip($zip, $zipPath, 'Failed to add file to archive', 'ZIP_ADD_FILE_ERROR');
			}
		}

		$zip->close();

		$sanitizedTitle = Request\File::sanitizeFilename($blank->title ?? '');
		$fileName = ($sanitizedTitle ?: self::FALLBACK_DOWNLOAD_FILENAME) . '.zip';

		return new CreateBlankArchiveResult($zipPath, $fileName);
	}

	private function resolveUniqueEntryName(string $rawName, array $usedNames): string
	{
		$name = Request\File::sanitizeFilename($rawName) ?? Request\File::getRandomName();

		if (!isset($usedNames[$name]))
		{
			return $name;
		}

		$dotPosition = Main\Text\UtfSafeString::getLastPosition($name, '.');
		$nameWithoutExt = $dotPosition === false ? $name : mb_substr($name, 0, $dotPosition);
		$extension = $dotPosition === false ? '' : mb_substr($name, $dotPosition + 1);

		$counter = 1;
		do
		{
			$candidate = $extension === ''
				? "$nameWithoutExt ($counter)"
				: "$nameWithoutExt ($counter).$extension"
			;
			$counter++;
		}
		while (isset($usedNames[$candidate]));

		return $candidate;
	}

	private function abortZip(\ZipArchive $zip, string $zipPath, string $message, string $code): Main\Result
	{
		$zip->close();
		if (Main\IO\File::isFileExists($zipPath))
		{
			Main\IO\File::deleteFile($zipPath);
		}

		return (new Main\Result())->addError(new Error($message, $code));
	}
}
