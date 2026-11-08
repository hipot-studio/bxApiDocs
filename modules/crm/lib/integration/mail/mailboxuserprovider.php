<?php

namespace Bitrix\Crm\Integration\Mail;

use Bitrix\Mail\Helper\Mailbox\SharedMailboxesManager;
use Bitrix\Mail\Public\Service\Mailbox\AddressResolver;
use Bitrix\Main\Loader;

final class MailboxUserProvider
{
	/**
	 * Returns mailbox owners and directly assigned users grouped by normalized email.
	 *
	 * @param string[] $emails
	 * @return array<string, array<int, int>>
	 */
	public static function getUserIdsByEmails(array $emails): array
	{
		if (empty($emails) || !Loader::includeModule('mail'))
		{
			return [];
		}

		$resolver = new AddressResolver();
		$mailboxEmails = [];
		foreach ($resolver->findBindingsForEmails($emails) as $email => $bindings)
		{
			foreach ($bindings as $binding)
			{
				$mailboxEmails[$binding->mailboxId][$email] = $email;
			}
		}
		if (empty($mailboxEmails))
		{
			return [];
		}

		$userIdsByEmail = [];
		$mailboxes = $resolver->findActiveMailboxesByIds(array_keys($mailboxEmails), 'imap');
		foreach ($mailboxes as $mailbox)
		{
			$mailboxId = (int)($mailbox['ID'] ?? 0);
			$userIds = SharedMailboxesManager::getUserIdsWithAccessToMailbox($mailboxId);
			$userIds[] = (int)($mailbox['USER_ID'] ?? 0);
			$userIds = array_filter(
				array_unique(array_map('intval', $userIds)),
				static fn(int $userId): bool => $userId > 0,
			);
			sort($userIds, SORT_NUMERIC);
			foreach ($mailboxEmails[$mailboxId] ?? [] as $email)
			{
				foreach ($userIds as $userId)
				{
					$userIdsByEmail[$email][$userId] = $userId;
				}
			}
		}

		return $userIdsByEmail;
	}
}
