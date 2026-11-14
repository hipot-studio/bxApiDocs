<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Main\Application;

/**
 * The named lock of the database, wrapped so that the launcher of the jobs can be tested without one
 * ({@see \Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationSliderLock} does the same for
 * the slider of the automation).
 *
 * @internal
 */
class ClientSummaryLock
{
	public function lock(string $name, int $timeout): bool
	{
		return Application::getConnection()->lock($name, $timeout);
	}

	public function unlock(string $name): void
	{
		Application::getConnection()->unlock($name);
	}
}
