<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\AttachmentHelper;
use Bitrix\Main\Engine\Controller;

class SyncingAttachments extends Controller
{
	public function resyncAttachmentsAction(int $messageId, int $mailboxId): bool
	{
		if(!MailboxAccess::hasCurrentUserAccessToMailbox($mailboxId, withSharedMailboxes: true))
		{
			return false;
		}

		$messageAttachments = new AttachmentHelper($mailboxId, $messageId);

		return $messageAttachments->update();
	}
}