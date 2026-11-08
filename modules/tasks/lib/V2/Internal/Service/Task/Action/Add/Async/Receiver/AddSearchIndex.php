<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Receiver;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Message;
use Bitrix\Tasks\V2\Internal\Service\Task\SearchIndexService;
use Bitrix\Tasks\V2\Internal\Service\Task\SearchIndexWriter;

class AddSearchIndex extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof Message\AddSearchIndex)
		{
			return;
		}

		(new SearchIndexService(new SearchIndexWriter()))->index((int)($message->task['ID'] ?? 0));
	}
}
