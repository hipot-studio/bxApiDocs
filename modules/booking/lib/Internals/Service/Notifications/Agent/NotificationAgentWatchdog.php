<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Internals\Service\Notifications\NotificationAgentManager;

/**
 * Hourly agent that ensures notification agents are registered
 * only on portals with active resources.
 * Deregisters them when no resources exist to avoid useless queries.
 */
class NotificationAgentWatchdog
{
	public static function execute(): string
	{
		NotificationAgentManager::sync();

		return '\\' . self::class . '::execute();';
	}
}
