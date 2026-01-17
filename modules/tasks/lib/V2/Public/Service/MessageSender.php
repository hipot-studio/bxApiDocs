<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service;

use Bitrix\Tasks\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

class MessageSender
{
	private readonly MessageSenderInterface $delegate;

	public function __construct()
	{
		$this->delegate = Container::getInstance()->get(MessageSenderInterface::class);
	}

	public function sendMessage(Entity\Task $task, AbstractNotify $notification): void
	{
		$this->delegate->sendMessage($task, $notification);
	}
}
