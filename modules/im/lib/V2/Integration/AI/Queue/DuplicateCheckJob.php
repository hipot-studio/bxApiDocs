<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Duplicate\DuplicateCheckService;

class DuplicateCheckJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$result = $this->event->getParameter('result');
		if (!($result instanceof Result))
		{
			// nothing to read the verdict from: release the parked task instead of holding it
			(new DuplicateCheckService())->handleFailedVerdict($parameters);

			return;
		}

		(new DuplicateCheckService())->handleVerdict($result->getJsonData() ?? [], $parameters);
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');

		(new DuplicateCheckService())->handleFailedVerdict($engine->getContext()->getParameters());
	}
}
