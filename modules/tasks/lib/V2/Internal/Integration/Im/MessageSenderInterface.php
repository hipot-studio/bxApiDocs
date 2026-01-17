<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Internal\Result\Result;

interface MessageSenderInterface
{
	public function sendMessage(Entity\Task $task, AbstractNotify $notification): Result;
}