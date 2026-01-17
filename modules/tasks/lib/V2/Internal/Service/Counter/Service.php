<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter;

use Bitrix\Tasks\Internals\Counter\CounterService;

class Service
{
	public function __construct
	(
		private readonly CounterService $service,
	)
	{
	}

	public function collect(int $taskId): void
	{
		$this->service->collectData($taskId);
	}

	public function send(Command\AbstractPayload $payload): void
	{
		$this->service->addEvent($payload->getCommand(), $payload->toArray());
	}
}
