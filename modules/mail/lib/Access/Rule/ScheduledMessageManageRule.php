<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Access\Model\ScheduledMessageModel;
use Bitrix\Mail\Helper\MailboxAccess;

class ScheduledMessageManageRule
{
	public function __construct(private readonly ScheduledMessageViewRule $viewRule = new ScheduledMessageViewRule())
	{
	}

	public function canManage(int $userId, ScheduledMessageModel $message): bool
	{
		return $userId === $message->getAuthorId() && $this->viewRule->canView($userId, $message);
	}

	/**
	 * @param array<int, ScheduledMessageModel> $messages
	 * @return array<int, bool> decisions indexed by message identifier
	 */
	public function canManageBatch(int $userId, array $messages): array
	{
		$mailboxIds = [];
		foreach ($messages as $message)
		{
			if ($message->getAuthorId() === $userId)
			{
				$mailboxIds[] = $message->getMailboxId();
			}
		}
		$accessibleMailboxIds = array_fill_keys(
			MailboxAccess::filterAccessibleMailboxIds($mailboxIds, $userId),
			true,
		);

		$decisions = [];
		foreach ($messages as $message)
		{
			$decisions[$message->getId()] = $message->getAuthorId() === $userId
				&& isset($accessibleMailboxIds[$message->getMailboxId()]);
		}

		return $decisions;
	}
}
