<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Core\Queue;

use Bitrix\Main\DI\ServiceLocator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Queue entry points (agents, routing) are created by the framework and have no place
 * to inject a logger into, so the shared one is taken from the container.
 */
class QueueLogger
{
	private const SERVICE_ID = 'calendar.service.queue.logger';

	public static function get(): LoggerInterface
	{
		$locator = ServiceLocator::getInstance();
		if (!$locator->has(self::SERVICE_ID))
		{
			return new NullLogger();
		}

		return $locator->get(self::SERVICE_ID);
	}
}
