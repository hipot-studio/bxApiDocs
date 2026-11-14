<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Main\Application;

/**
 * Runs a piece of work after the answer of the request has left, wrapped so that the launch can be
 * tested where it stands ({@see \Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationSliderLock}
 * does the same for the lock of the slider).
 *
 * What is paid for it: the answer is already gone, so a fatal error or a timeout of the process takes
 * the whole generation with it - the stub, the queueing and the refusal alike. Nothing catches it:
 * {@see ClientSummaryLaunchService::deferGeneration()} wraps the job in a try, and a process that died
 * never reaches the catch. The chat then stays as it was at the moment of the answer - open and empty -
 * and the only way out of it is another click on the button of the card, which the person is not told
 * about anywhere.
 *
 * @internal
 */
class ClientSummaryBackgroundRunner
{
	public function run(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}
}
