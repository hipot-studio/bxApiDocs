<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService;
use Bitrix\Mail\Internal\Async\Message\RepairConnectionRequestChatsMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

final class RepairConnectionRequestChatsReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof RepairConnectionRequestChatsMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		(new MailboxConnectionRequestService())->repairPendingRequestChats();
	}
}
