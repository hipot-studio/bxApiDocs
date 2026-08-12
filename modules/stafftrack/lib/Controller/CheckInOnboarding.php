<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Public\Provider\CheckInOnboardingProvider;

class CheckInOnboarding extends Controller
{
	public function getShouldShowAction(): bool
	{
		return (new CheckInOnboardingProvider())->getShouldShow(
			(int)CurrentUser::get()->getId()
		);
	}

	public function registerShownAction(): void
	{
		(new CheckInOnboardingProvider())->registerShown(
			(int)CurrentUser::get()->getId()
		);
	}

	public function enableAllAction(): bool
	{
		(new CheckInOnboardingProvider())->enableAll();

		return true;
	}
}
