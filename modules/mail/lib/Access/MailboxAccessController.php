<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Main\Access\AccessibleItem;

final class MailboxAccessController extends BaseMailAccessController
{
	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return !is_null($itemId) ? MailboxModel::createFromId($itemId) : null;
	}
}
