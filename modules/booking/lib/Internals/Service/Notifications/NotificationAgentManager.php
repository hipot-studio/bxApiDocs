<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications;

use Bitrix\Booking\Internals\Service\Notifications\Agent\ConfirmationCounterAgent;
use Bitrix\Booking\Internals\Service\Notifications\Agent\DelayedCounterAgent;
use Bitrix\Booking\Internals\Service\Notifications\Agent\NotificationAgent;
use Bitrix\Booking\Internals\Service\Notifications\Agent\RetryAgent;
use Bitrix\Main\Application;

/**
 * Manages registration and deregistration of notification-related agents.
 * Agents are only needed when the portal has active (non-deleted) resources.
 */
class NotificationAgentManager
{
	private const MODULE_ID = 'booking';

	private const MANAGED_AGENTS = [
		['class' => NotificationAgent::class, 'interval' => 60],
		['class' => ConfirmationCounterAgent::class, 'interval' => 60],
		['class' => DelayedCounterAgent::class, 'interval' => 60],
		['class' => RetryAgent::class, 'interval' => 900],
	];

	public static function registerAll(): void
	{
		foreach (self::MANAGED_AGENTS as $agent)
		{
			$name = '\\' . $agent['class'] . '::execute();';

			\CAgent::AddAgent(
				name: $name,
				module: self::MODULE_ID,
				interval: $agent['interval'],
				next_exec: \ConvertTimeStamp(
					time() + \CTimeZone::GetOffset() + $agent['interval'],
					'FULL',
				),
				existError: false,
			);
		}
	}

	public static function unregisterAll(): void
	{
		foreach (self::MANAGED_AGENTS as $agent)
		{
			$name = '\\' . $agent['class'] . '::execute();';

			\CAgent::RemoveAgent($name, self::MODULE_ID);
		}
	}

	/**
	 * Checks if active resources exist
	 * and registers or unregisters agents accordingly.
	 */
	public static function sync(): void
	{
		if (self::hasActiveResources())
		{
			self::registerAll();
		}
		else
		{
			self::unregisterAll();
		}
	}

	private static function hasActiveResources(): bool
	{
		$sql = "SELECT 1 FROM b_booking_resource_data rd WHERE rd.IS_DELETED = 'N' LIMIT 1";

		return (bool)Application::getConnection()->query($sql)->fetch();
	}
}
