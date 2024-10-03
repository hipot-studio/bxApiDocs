<?php

namespace Bitrix\IntranetMobile\Integration\Main;

use Bitrix\Main\Config\Option;

class Event
{
	public static function onUserInitialize($userId): void
	{
		if (
			Option::get('intranetmobile', 'isMiniProfileEnabled', 'Y') === 'Y'
			&& defined('BX_MOBILE')
		)
		{
			\CUserOptions::SetOption(
				'intranetmobile',
				'isNeedToShowMiniProfile',
				true,
				false,
				$userId
			);
		}
	}
}