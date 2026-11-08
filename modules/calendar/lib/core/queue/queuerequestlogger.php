<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Core\Queue;

use Bitrix\Calendar\Synchronization\Internal\Service\Logger\RequestLogger;

/**
 * The queue swallows its own failures on purpose: a routing, claim or delivery error must not stop
 * the pass that met it. The journal is then the only account of what happened, so it is written on
 * an ordinary installation as well, where the synchronization debug option stays off.
 *
 * Only the failure paths are journalled, so writing by default costs the portal nothing while the
 * queue is healthy.
 */
class QueueRequestLogger extends RequestLogger
{
	protected function isEnabled(): bool
	{
		return true;
	}
}
