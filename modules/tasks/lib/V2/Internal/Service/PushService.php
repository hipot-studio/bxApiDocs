<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class PushService
{
	private const MODULE_ID = 'tasks';

	public function getModuleName(): string
	{
		return \Bitrix\Tasks\Integration\Pull\PushService::MODULE_NAME;
	}

	public function addEvent(UserCollection $recipients, array $event): void
	{
		\Bitrix\Tasks\Integration\Pull\PushService::addEvent(
			$recipients->getIds(),
			$event,
		);
	}

	public function addEventByParameters(UserCollection $recipients, string $command, array $parameters): void
	{
		$event['command'] = $command;
		$event['module_id'] = self::MODULE_ID;
		$event['params'] = $parameters;

		$this->addEvent($recipients, $event);
	}

	public function addEventByTag(string $tag, string $command, array $parameters): void
	{
		$params = [
			'module_id' => self::MODULE_ID,
			'command' => $command,
			'params' => $parameters,
		];

		\Bitrix\Tasks\Integration\Pull\PushService::addEventByTag($tag, $params);
	}
}
