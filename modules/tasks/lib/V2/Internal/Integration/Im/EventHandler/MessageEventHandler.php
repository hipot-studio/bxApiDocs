<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler;

use Bitrix\Tasks\V2\Internal\DI\Container;

class MessageEventHandler
{
 	public static function onAfterMessagesDelete(int $id): void
	{
		$service = Container::getInstance()->getResultService();

		$service->deleteMessageLink($id);
	}
}
