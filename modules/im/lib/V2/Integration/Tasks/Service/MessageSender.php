<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Public;

class MessageSender
{
	private ?Public\Service\MessageSender $messageSender = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->messageSender = Container::getInstance()->get(Public\Service\MessageSender::class);
		}
	}

	public function sendMessage(Task $task, AbstractNotify $notification): void
	{
		$this->messageSender?->sendMessage($task, $notification);
	}
}
