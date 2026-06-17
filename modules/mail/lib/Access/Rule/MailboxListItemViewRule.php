<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Rule;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\Access\AccessibleItem;

class MailboxListItemViewRule extends MailboxBaseRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (parent::execute($item, $params))
		{
			return true;
		}

		if (!($item instanceof MailboxModel) || !$item->getId())
		{
			return false;
		}

		return MailboxAccess::isMailboxSharedWithUser(
			(int)$item->getId(),
			$this->user->getUserId(),
		);
	}
}
