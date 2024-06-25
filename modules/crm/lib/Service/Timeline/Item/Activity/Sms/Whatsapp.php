<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity\Sms;

use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Main\Localization\Loc;

// IMPORTANT: DO NOT REMOVE THIS FILE - loading this so as not to copy the same phrases
Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/crm/lib/Service/Timeline/Item/Activity/Sms/Sms.php');

final class Whatsapp extends Sms
{
	protected function getActivityTypeId(): string
	{
		return 'Whatsapp';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_TITLE_ACTIVITY_WHATSAPP_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Icon::WHATSAPP;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::CHANNEL_WHATSAPP)->createLogo();
	}
}
