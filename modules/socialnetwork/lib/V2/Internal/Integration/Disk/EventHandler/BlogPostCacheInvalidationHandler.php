<?php

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Disk\EventHandler;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Main\Event;
use Bitrix\Socialnetwork\V2\Internal\Integration\Disk\Service\BlogPostCacheInvalidationService;

class BlogPostCacheInvalidationHandler
{
	public static function onAfterMarkDeletedObject(Event $event): void
	{
		[$diskObject] = $event->getParameters();
		self::invalidateDiskFileCache($diskObject);
	}

	public static function onAfterRestoreObject(Event $event): void
	{
		[$diskObject] = $event->getParameters();
		self::invalidateDiskFileCache($diskObject);
	}

	private static function invalidateDiskFileCache(BaseObject $diskObject): void
	{
		if ($diskObject instanceof File)
		{
			BlogPostCacheInvalidationService::invalidateCacheByDiskFileId($diskObject->getId());
		}
	}
}
