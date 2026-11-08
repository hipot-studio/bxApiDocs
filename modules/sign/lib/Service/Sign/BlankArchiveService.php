<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Sign\Item;
use Bitrix\Sign\Result\Service\Sign\CreateBlankArchiveResult;
use Bitrix\Sign\Util\Filename;
use Bitrix\Sign\Util\Request;

class BlankArchiveService
{
	// Public because the single file download path answers a vanished file with the same code.
	public const ERROR_CODE_BLANK_FILE_NOT_FOUND = 'BLANK_FILE_NOT_FOUND';

	private const FALLBACK_DOWNLOAD_FILENAME = 'blank';

	public function createFromBlank(Item\Blank $blank): Main\Result|CreateBlankArchiveResult
	{
		return $this->createFromBlanks([['blank' => $blank, 'entryBaseName' => null]]);
	}

	/**
	 * @param list<array{blank: Item\Blank, entryBaseName: string|null}> $entries
	 */
	public function createFromBlanks(
		array $entries,
		?string $archiveTitle = null,
	): Main\Result|CreateBlankArchiveResult
	{
		if (!class_exists(\ZipArchive::class))
		{
			return (new Main\Result())->addError(new Error('ZipArchive is not available', 'ZIP_NOT_AVAILABLE'));
		}

		$entries = array_values($entries);
		if ($entries === [] || $this->hasBlankWithoutFiles($entries))
		{
			return (new Main\Result())->addError(new Error('Blank has no files', 'BLANK_NO_FILES'));
		}

		$firstBlank = $entries[0]['blank'];
		$zipPath = \CTempFile::GetFileName('blank_' . $firstBlank->id . '.zip');
		$dir = Main\IO\Path::getDirectory($zipPath);
		(new Main\IO\Directory($dir))->create();

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE) !== true)
		{
			return (new Main\Result())->addError(new Error('Failed to create archive', 'ZIP_CREATE_ERROR'));
		}

		$usedNames = [];
		foreach ($entries as $entry)
		{
			foreach ($entry['blank']->fileCollection as $file)
			{
				$fileArray = \CFile::MakeFileArray($file->id);
				$absPath = $fileArray['tmp_name'] ?? null;
				if (!$absPath || !Main\IO\File::isFileExists($absPath))
				{
					return $this->abortZip($zip, $zipPath, 'Blank file not found', self::ERROR_CODE_BLANK_FILE_NOT_FOUND);
				}

				$entryName = $this->resolveUniqueEntryName(
					Filename::compose($fileArray['name'] ?? $file->name, $entry['entryBaseName'] ?? null),
					$usedNames
				);
				$usedNames[self::entryNameKey($entryName)] = true;
				if (!$zip->addFile($absPath, $entryName))
				{
					return $this->abortZip($zip, $zipPath, 'Failed to add file to archive', 'ZIP_ADD_FILE_ERROR');
				}
			}
		}

		$zip->close();

		$sanitizedTitle = Request\File::sanitizeFilename($archiveTitle ?? $firstBlank->title ?? '');
		$fileName = Filename::forFileSystem(($sanitizedTitle ?: self::FALLBACK_DOWNLOAD_FILENAME) . '.zip');

		return new CreateBlankArchiveResult($zipPath, $fileName);
	}

	/**
	 * @param list<array{blank: Item\Blank, entryBaseName: string|null}> $entries
	 */
	private function hasBlankWithoutFiles(array $entries): bool
	{
		foreach ($entries as $entry)
		{
			$fileCollection = $entry['blank']->fileCollection;
			if ($fileCollection === null || $fileCollection->count() === 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The name is sanitized first and only then fitted to the file system: a title like `CON:` is a
	 * device name only after the colon is gone, and the check has to see the name the user will
	 * actually get.
	 *
	 * @param array<string, true> $usedNames keys of the names already taken by the archive
	 */
	private function resolveUniqueEntryName(string $rawName, array $usedNames): string
	{
		$sanitized = Request\File::sanitizeFilename($rawName) ?? Request\File::getRandomName();
		$name = Filename::forFileSystem($sanitized);

		if (!isset($usedNames[self::entryNameKey($name)]))
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
		while (isset($usedNames[self::entryNameKey($candidate)]));

		return $candidate;
	}

	/**
	 * Names are told apart the way a file system tells them apart: `Report.pdf` and `report.pdf` are
	 * one and the same path on Windows and macOS, so the second one has to get a suffix. The entry
	 * itself keeps the case of the title.
	 */
	private static function entryNameKey(string $entryName): string
	{
		return mb_strtolower($entryName);
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
