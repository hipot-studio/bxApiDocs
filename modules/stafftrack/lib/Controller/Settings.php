<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Provider\UserProvider;

class Settings extends Controller
{
	protected UserProvider $userProvider;

	protected function init(): void
	{
		parent::init();

		$this->userProvider = UserProvider::getInstance();
	}

	public function turnCheckInSettingOnAction(): void
	{
		$userId = CurrentUser::get()->getId();

		if (!$this->userProvider->isUserAdmin($userId))
		{
			return;
		}

		Feature::turnCheckInSettingOn();
	}

	public function turnCheckInSettingOffAction(): void
	{
		$userId = CurrentUser::get()->getId();

		if (!$this->userProvider->isUserAdmin($userId))
		{
			return;
		}

		Feature::turnCheckInSettingOff();
	}
}