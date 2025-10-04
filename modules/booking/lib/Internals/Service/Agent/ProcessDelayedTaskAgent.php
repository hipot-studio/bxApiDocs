<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Agent;

use Bitrix\Booking\Internals\Service\DelayedTask\DelayedTaskService;

class ProcessDelayedTaskAgent
{
	public static function execute(): string
	{
		(new DelayedTaskService())->processPending();

		return '\\' . self::class . '::execute();';
	}
}
