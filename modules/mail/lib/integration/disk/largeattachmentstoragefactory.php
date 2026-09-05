<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Main\Loader;

final class LargeAttachmentStorageFactory
{
	public static function getInstance(): LargeAttachmentStorageInterface
	{
		return self::createStorage(self::isRequiredDiskApiAvailable());
	}

	private static function createStorage(bool $isRequiredDiskApiAvailable): LargeAttachmentStorageInterface
	{
		if ($isRequiredDiskApiAvailable)
		{
			return new RealLargeAttachmentStorage();
		}

		return new StubLargeAttachmentStorage();
	}

	private static function isRequiredDiskApiAvailable(): bool
	{
		if (!Loader::includeModule('disk'))
		{
			return false;
		}

		return method_exists(\Bitrix\Disk\Storage::class, 'getFolderForMailAttachments')
			&& method_exists(\Bitrix\Disk\ExternalLink::class, 'canEditSettings');
	}
}
