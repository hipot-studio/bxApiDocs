<?php

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject;

use Bitrix\Disk\Public\Event\OnAfterDeleteAttachedObjectEvent;
use Bitrix\Socialnetwork\V2\Internal\Integration\Disk\Service\BlogPostCacheInvalidationService;

class InvalidateCache
{
	public function __invoke(OnAfterDeleteAttachedObjectEvent $event): void
	{
		$attachedObjectData = $event->getParameter('attachedObject');

		if (empty($attachedObjectData))
		{
			return;
		}

		BlogPostCacheInvalidationService::invalidateCacheByAttachedObjectData($attachedObjectData);
	}
}