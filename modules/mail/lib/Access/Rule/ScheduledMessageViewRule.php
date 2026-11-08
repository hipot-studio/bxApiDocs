<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Access\Model\ScheduledMessageModel;
use Bitrix\Mail\Helper\MailboxAccess;

class ScheduledMessageViewRule
{
	public function canView(int $userId, ScheduledMessageModel $message): bool
	{
		return $userId > 0 && MailboxAccess::hasUserAccessToMailbox(
			$message->getMailboxId(),
			$userId,
			withSharedMailboxes: true,
		);
	}
}
