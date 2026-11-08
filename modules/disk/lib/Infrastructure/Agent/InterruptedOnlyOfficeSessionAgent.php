<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

use Bitrix\Disk\Internal\Service\OnlyOffice\Save\InterruptedSessionService;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Ends the editing sessions the editor server went silent on. The work itself is in the service; the
 * agent only brings it to the schedule and stays there whatever one run found.
 */
final class InterruptedOnlyOfficeSessionAgent extends BaseDiskAgent
{
	protected function runInternal(): RunResult
	{
		ServiceLocator::getInstance()
			->get(InterruptedSessionService::class)
			->closeInterruptedSessions()
		;

		return new RunResult(true);
	}
}
