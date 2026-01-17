<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\Pull\PushService;

class Service
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('pull');
	}

	public function send(array|int $recipients, AbstractPayload $payload): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		PushService::addEvent($recipients, [
			'module_id' => 'tasks',
			'command' => $payload->getCommand(),
			'params' => $payload->toArray(),
		]);
	}
}
