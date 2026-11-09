<?php

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\File;
use Bitrix\Landing\Internals\FileTable;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;

Loc::loadMessages(__FILE__);

/**
 * Persistence of markup package files in b_file with block bindings.
 *
 * Files are immutable: a new version stores bytes only for changed content. Every file of
 * a package is stored under a random name with a neutral extension, so a direct hit on its
 * address never renders a document in the portal origin; files are served by the controller
 * of the package files, which sets the content type from the manifest.
 */
class FileStore
{
	/**
	 * Extension of every stored package file; must never be rendered by the web server.
	 */
	public const ENTRY_EXTENSION = 'dat';

	/**
	 * Refusal codes of an asset save; the build stops on them (ADR § 3.5).
	 */
	public const ERROR_ASSET_NOT_STORED = 'MARKUP_ASSET_NOT_STORED';
	public const ERROR_DISK_QUOTA = 'MARKUP_DISK_QUOTA';

	/**
	 * Content types a stored package file may be served with.
	 */
	private const SERVABLE_TYPES = [
		'text/css',
		'text/javascript',
		'application/javascript',
		'image/png',
		'image/jpeg',
		'image/webp',
		'image/gif',
		'video/mp4',
	];

	private const SAVE_DIR = 'landing_markup';
	private const MODULE_ID = 'landing';

	/**
	 * Name every package file is stored under (see saveFile() callers).
	 */
	private const STORED_NAME_PATTERN = '/^[0-9a-z]{32}\.' . self::ENTRY_EXTENSION . '$/iD';

	/**
	 * Saves content under a random neutral name, returns b_file id. Used for the entry
	 * document and for upload buffer files: neither may ever be served as a document.
	 */
	public static function saveEntry(string $content): int
	{
		return self::saveFile(Random::getString(32) . '.' . self::ENTRY_EXTENSION, $content);
	}

	/**
	 * Saves an asset file under a random neutral name; the package path is kept by the manifest.
	 * A refusal is an error: MARKUP_DISK_QUOTA when the portal disk quota is exhausted,
	 * MARKUP_ASSET_NOT_STORED otherwise.
	 * @param \CDiskQuota|null $quota Quota of the portal; the kernel one by default.
	 * @param FailureInjection|null $injection Dev failures of a stand; ignored on a production portal.
	 * @return Result Data: ['fileId' => int].
	 */
	public static function saveAsset(
		string $path,
		string $content,
		?\CDiskQuota $quota = null,
		?FailureInjection $injection = null,
	): Result
	{
		$result = new Result();

		$injection ??= new FailureInjection();
		if (
			$injection->isDiskQuotaExhausted()
			|| !($quota ?? new \CDiskQuota())->checkDiskQuota(['size' => strlen($content)])
		)
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_FILESTORE_DISK_QUOTA', ['#PATH#' => $path]),
				self::ERROR_DISK_QUOTA,
				['path' => $path]
			));
		}

		$fileId = self::saveFile(Random::getString(32) . '.' . self::ENTRY_EXTENSION, $content);
		if ($fileId <= 0)
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_FILESTORE_ASSET_NOT_STORED', ['#PATH#' => $path]),
				self::ERROR_ASSET_NOT_STORED,
				['path' => $path]
			));
		}

		return $result->setData(['fileId' => $fileId]);
	}

	/**
	 * Reads back the content of a package file (works for local and cloud storage);
	 * null for any other b_file record.
	 */
	public static function readContent(int $fileId): ?string
	{
		if (!self::isPackageFile($fileId))
		{
			return null;
		}

		$fileArray = \CFile::makeFileArray($fileId);
		if (!is_array($fileArray) || empty($fileArray['tmp_name']))
		{
			return null;
		}

		$content = @file_get_contents($fileArray['tmp_name']);

		return $content === false ? null : $content;
	}

	/**
	 * Content type of a stored file by its b_file record, limited to the types of a package;
	 * for files of older versions whose manifest carries no type.
	 */
	public static function getContentType(int $fileId): string
	{
		$file = $fileId > 0 ? \CFile::getFileArray($fileId) : false;
		$type = is_array($file) ? mb_strtolower((string)($file['CONTENT_TYPE'] ?? '')) : '';

		return in_array($type, self::SERVABLE_TYPES, true) ? $type : Manifest::TYPE_UNKNOWN;
	}

	/**
	 * Reads the entry document of the version described by the manifest.
	 */
	public static function readEntry(Manifest $manifest): ?string
	{
		return self::readContent($manifest->getEntryFileId());
	}

	/**
	 * Whether the b_file record was stored by this class. A manifest refers only to such files:
	 * any other id in it is never read, served or bound to a block.
	 */
	public static function isPackageFile(int $fileId): bool
	{
		$file = $fileId > 0 ? \CFile::getFileArray($fileId) : false;

		return is_array($file) && self::isPackageFileRecord($file);
	}

	/**
	 * @param int[] $fileIds
	 * @return int[] The package files among the given ones, in the given order, without duplicates.
	 */
	public static function filterPackageFileIds(array $fileIds): array
	{
		$fileIds = array_values(array_unique(array_filter(
			array_map('intval', $fileIds),
			static fn(int $fileId): bool => $fileId > 0,
		)));
		if (!$fileIds)
		{
			return [];
		}

		$packageFileIds = [];
		$res = Main\FileTable::getList([
			'select' => ['ID', 'MODULE_ID', 'SUBDIR', 'ORIGINAL_NAME'],
			'filter' => [
				'@ID' => $fileIds,
				'=MODULE_ID' => self::MODULE_ID,
			],
		]);
		while ($row = $res->fetch())
		{
			if (self::isPackageFileRecord($row))
			{
				$packageFileIds[(int)$row['ID']] = true;
			}
		}

		return array_values(array_filter(
			$fileIds,
			static fn(int $fileId): bool => isset($packageFileIds[$fileId]),
		));
	}

	/**
	 * Binds a file to the block; $temp marks the binding for the clearTempFiles agent
	 * until the build commits.
	 */
	public static function bindToBlock(int $blockId, int $fileId, bool $temp = true): void
	{
		File::addToBlock($blockId, $fileId, $temp);
	}

	/**
	 * Drops the TEMP mark from all block bindings: the build has been committed.
	 */
	public static function commitBlockFiles(int $blockId): void
	{
		$res = FileTable::getList([
			'select' => ['ID'],
			'filter' => [
				'ENTITY_ID' => $blockId,
				'=ENTITY_TYPE' => File::ENTITY_TYPE_BLOCK,
				'=TEMP' => 'Y',
				'>FILE_ID' => 0,
			],
		]);
		while ($row = $res->fetch())
		{
			FileTable::update($row['ID'], ['TEMP' => 'N']);
		}
	}

	public static function hash(string $content): string
	{
		return hash('sha256', $content);
	}

	private static function saveFile(string $name, string $content): int
	{
		$fileId = (int)\CFile::saveFile(
			[
				'name' => $name,
				'content' => $content,
				'MODULE_ID' => self::MODULE_ID,
			],
			self::SAVE_DIR
		);

		return $fileId;
	}

	/**
	 * A package file is a landing file of SAVE_DIR. The kernel duplicate control may point a new
	 * record to the directory of an older identical file of another module, then the stored name tells.
	 */
	private static function isPackageFileRecord(array $file): bool
	{
		if ((string)($file['MODULE_ID'] ?? '') !== self::MODULE_ID)
		{
			return false;
		}

		$subdir = trim((string)($file['SUBDIR'] ?? ''), '/');

		return $subdir === self::SAVE_DIR
			|| str_starts_with($subdir, self::SAVE_DIR . '/')
			|| preg_match(self::STORED_NAME_PATTERN, (string)($file['ORIGINAL_NAME'] ?? '')) === 1
		;
	}
}
