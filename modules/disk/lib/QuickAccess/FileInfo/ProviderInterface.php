<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

interface ProviderInterface
{
	/**
	 *
	 * @param mixed $file - some type of file - AttachedObject, BaseObject, of id from b_file.
	 * @return static|null
	 */
	public static function create(mixed $file): ?static;

	public function getName(): string;

	public function getId(): int;

	public function getFileInfo(): ?FileInfoDto;
}