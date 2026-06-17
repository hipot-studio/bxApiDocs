<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Message;
use Bitrix\Main;

class MailboxCountersService
{
	public function getCountersForUser(int $userId, ?int $mailboxId = null): array
	{
		if ($userId <= 0)
		{
			return ['total' => 0, 'mailboxes' => [], 'folders' => null];
		}

		$perMailbox = [];
		foreach (Message::getCountersForUserMailboxes($userId) as $id => $row)
		{
			$perMailbox[(int)$id] = (int)($row['UNSEEN'] ?? 0);
		}

		$folders = null;
		if ($mailboxId)
		{
			try
			{
				$folders = Mailbox::createInstance($mailboxId)->getDirsWithUnseenMailCounters();
			}
			catch (Main\ObjectException)
			{
			}
		}

		return [
			'total' => (int)\CUserCounter::GetValue($userId, 'mail_unseen'),
			'mailboxes' => $perMailbox,
			'folders' => $folders,
		];
	}
}
