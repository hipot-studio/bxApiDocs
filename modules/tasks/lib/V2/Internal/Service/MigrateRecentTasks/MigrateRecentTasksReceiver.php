<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\MigrateRecentTasks;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\MigrateRecentTasks\MigrateRecentTaskService;

class MigrateRecentTasksReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof MigrateRecentTasksMessage)
		{
			return;
		}

		Container::getInstance()->get(MigrateRecentTaskService::class)->execute($message->taskId);
	}
}
