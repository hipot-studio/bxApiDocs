<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Model\UserItemModel;
use Bitrix\Main\Access\AccessibleItem;

final class MailAccessController extends BaseMailAccessController
{
	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return !is_null($itemId) ? UserItemModel::createFromId($itemId) : null;
	}
}
