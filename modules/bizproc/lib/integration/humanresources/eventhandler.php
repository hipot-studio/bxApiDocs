<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\HumanResources;

use Bitrix\Main\Event;

final class EventHandler
{
	public static function onAiReportsEnabled(Event $event): void
	{
		$nodeId = (int)($event->getParameter('nodeId') ?? 0);
		if ($nodeId <= 0)
		{
			return;
		}

		$userId = (int)($event->getParameter('userId') ?? 0);
		if ($userId <= 0)
		{
			return;
		}

		(new DayPlannerStarter())->handleNodeEnabled($nodeId, $userId);
	}
}
