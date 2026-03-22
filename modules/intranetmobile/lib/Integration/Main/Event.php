<?php

namespace Bitrix\IntranetMobile\Integration\Main;

use Bitrix\Main\Config\Option;

class Event
{
	public static function onAfterUserAuthorizeHandler($params): void
	{
		$hasNameOrLastName = !empty($params['user_fields']['NAME']) || !empty($params['user_fields']['LAST_NAME']);

		if (
			!defined('BX_MOBILE')
			|| !$params['update'] // rest auth
			|| Option::get('intranetmobile', 'isMiniProfileEnabled', 'Y') !== 'Y'
			|| in_array($params['user_fields']['EXTERNAL_AUTH_ID'], \Bitrix\Main\UserTable::getExternalUserTypes())
			|| \CUserOptions::GetOption('intranetmobile', 'isMiniProfileShowed', false, $params['user_fields']['ID']) === true
			|| $hasNameOrLastName
		)
		{
			return;
		}

		\CUserOptions::SetOption(
			'intranetmobile',
			'isNeedToShowMiniProfile',
			true,
			false,
			$params['user_fields']['ID'],
		);
	}
}
