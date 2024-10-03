<?php

namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Main\Engine\Controller;

class UserProfile extends Controller
{
	public function isNeedToShowMiniProfileAction(): bool
	{
		$isNeedToShow = \CUserOptions::GetOption('intranetmobile', 'isNeedToShowMiniProfile', false) ?? false;

		if ($isNeedToShow)
		{
			\CUserOptions::DeleteOption('intranetmobile', 'isNeedToShowMiniProfile');
		}

		return $isNeedToShow;
	}
}