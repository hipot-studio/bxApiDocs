<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage\Booking;

use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Main\Localization\Loc;

class WaitListItemCreated extends LogMessage
{
	public function getType(): string
	{
		return 'WaitListItemCreated';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_LOG_WAIT_LIST_ITEM_CREATED_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Icon::INFO;
	}
}
