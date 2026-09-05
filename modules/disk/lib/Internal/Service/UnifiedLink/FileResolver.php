<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\File;
use Bitrix\Disk\Version;

class FileResolver
{
	public static function resolve(File $file, ?Version $version = null): File
	{
		$objectFromVersion = self::resolveVersion($file, $version)?->getObject();

		return $objectFromVersion ?? $file;
	}

	/**
	 * A versionId travels in the query of the unified link of a file, so the only revision it may name
	 * is one of that file. A revision of another object is dropped instead of answered for: it arrives
	 * from the request, and its own object would otherwise be resolved in place of the addressed one.
	 */
	public static function resolveVersion(File $file, ?Version $version = null): ?Version
	{
		if ($version === null)
		{
			return null;
		}

		return (int)$version->getObjectId() === (int)$file->getRealObjectId() ? $version : null;
	}
}
