<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity\Sms;

use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Main\Localization\Loc;

final class Telegram extends Sms
{
	protected function getActivityTypeId(): string
	{
		return 'Telegram';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_TITLE_ACTIVITY_TELEGRAM_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Icon::TELEGRAM;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::CHANNEL_TELEGRAM)->createLogo();
	}
}
