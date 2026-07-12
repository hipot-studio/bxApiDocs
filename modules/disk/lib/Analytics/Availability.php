<?php
declare(strict_types=1);

namespace Bitrix\Disk\Analytics;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\NotImplementedException;

class Availability
{
	protected static array $availableForAnalytics = [
		TypeFile::DOCUMENT => true,
		TypeFile::PDF => true,
	];

	/**
	 * @param BaseObject $baseObject
	 * @return bool
	 * @throws NotImplementedException
	 */
	public static function isAvailableForObject(BaseObject $baseObject): bool
	{
		if (!$baseObject instanceof File)
		{
			return false;
		}

		$contentType = $baseObject->getFile()['CONTENT_TYPE'] ?? '';

		if ($contentType === 'text/plain' || $baseObject->getExtension() === 'txt')
		{
			return false;
		}

		$fileType = (int)$baseObject->getTypeFile();

		return self::$availableForAnalytics[$fileType] ?? false;
	}
}
