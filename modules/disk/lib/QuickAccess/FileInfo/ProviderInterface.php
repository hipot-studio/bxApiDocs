<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

interface ProviderInterface
{
	/**
	 * @param mixed $file - some type of file - AttachedObject, BaseObject, of id from b_file.
	 * @return static|null
	 */
	public static function create(mixed $file): ?static;

	/**
	 * Returns the file name for the file represented by this provider.
	 * This name may differ from the $filename in FileInfoDto and is used for Content-Disposition header.
	 * For example, for disk files it returns the original name, while FileInfoDto contains the name from b_file,
	 * which may be different.
	 *
	 * @return string
	 */
	public function getFileName(): string;

	/**
	 * Returns the id of the file in b_file table for the file represented by this provider.
	 * @return int
	 */
	public function getBFileId(): int;

	public function getFileInfo(): ?FileInfoDto;

	/**
	 * Returns a stable identifier of the underlying source represented by this provider.
	 * It must be deterministic and unique within the provider's domain.
	 * @return string
	 */
	public function getSourceId(): string;
}