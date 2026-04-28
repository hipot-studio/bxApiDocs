<?php

namespace Bitrix\Crm\Import\Factory;

use Bitrix\Crm\Import\Enum\File\Extension;
use Bitrix\Crm\Import\Enum\TemporaryFileType;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Engine\CurrentUser;
use CTempFile;

final class TemporaryFileFactory
{
	private const TEMPLATE = 'temporary_import_filename_#USER_ID#_#TYPE#_#IMPORT_FILE_ID#';

	private const TTL = 3600 * 2; // 2 hours
	private const TTL_IN_HOURS = self::TTL / 3600;

	public function __construct(
		private readonly PersistentStorageInterface $storage,
	)
	{
	}

	public function create(
		string $importFileId,
		TemporaryFileType $type,
		Extension $extension,
	): string
	{
		$dir = CTempFile::GetDirectoryName(hours_to_keep_files: self::TTL_IN_HOURS);
		CheckDirPath($dir);

		$filename = $dir . "{$type->value}.{$extension->value}";

		$key = $this->buildKey($importFileId, $type);

		$this->storage->set($key, $filename, self::TTL);

		return $filename;
	}

	public function getOrCreate(
		string $importFileId,
		TemporaryFileType $type,
		Extension $extension,
	): string
	{
		return $this->get($importFileId, $type) ?? $this->create($importFileId, $type, $extension);
	}

	public function get(string $importFileId, TemporaryFileType $type): ?string
	{
		$key = $this->buildKey($importFileId, $type);

		return $this->storage->get($key);
	}

	private function buildKey(string $importFileId, TemporaryFileType $type): string
	{
		return strtr(self::TEMPLATE, [
			'#USER_ID#' => CurrentUser::get()->getId(),
			'#TYPE#' => $type->value,
			'#IMPORT_FILE_ID#' => $importFileId,
		]);
	}
}
