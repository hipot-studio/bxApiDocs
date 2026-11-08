<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject;

use Bitrix\Disk\Public\Event\OnAfterDeleteAttachedObjectEvent;
use Bitrix\Tasks\Integration\Disk\Connector\Task;
use Bitrix\Tasks\Integration\Disk\Connector\Task\Template;

class DetachFile
{
	public function __invoke(OnAfterDeleteAttachedObjectEvent $event): void
	{
		$parameters = $event->getParameters()['attachedObject'] ?? [];

		if (empty($parameters))
		{
			return;
		}

		$entityType = $parameters['ENTITY_TYPE'] ?? null;
		if ($entityType === null)
		{
			return;
		}

		$attachId = (int)($parameters['ID'] ?? 0);
		$entityId = (int)($parameters['ENTITY_ID'] ?? 0);
		$objectId = (int)($parameters['OBJECT_ID'] ?? 0);

		match ($entityType)
		{
			Task::class => (new Action\DetachFileFromTask())($entityId, $attachId, $objectId),
			Template::class => (new Action\DetachFileFromTemplate())($entityId, $attachId, $objectId),
			default => null,
		};
	}
}
