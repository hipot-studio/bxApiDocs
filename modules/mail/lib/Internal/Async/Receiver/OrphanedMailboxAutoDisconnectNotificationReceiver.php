<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Main;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Integration\Intranet\UserService;
use Bitrix\Mail\Internal\Async\Message\OrphanedMailboxAutoDisconnectNotificationMessage;

class OrphanedMailboxAutoDisconnectNotificationReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof OrphanedMailboxAutoDisconnectNotificationMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		foreach (UserService::getAdminUserIds() as $adminId)
		{
			Notification::notifyAdminAboutOrphanedMailboxAutoDisconnect(
				$adminId,
				$message->mailboxId,
				$message->mailboxEmail,
			);
		}
	}
}
