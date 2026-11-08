<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Public\Provider\CheckInOnboardingProvider;

/**
 * Every action that changes state is restricted to POST by an attribute rather than by an override of
 * getDefaultPreFilters(): the attribute is an EnablePrefilter, so it is appended to the defaults of the
 * base controller (Authentication, Csrf), while an override would replace them. enableAll() switches the
 * feature on for a whole department, so it is the one that decides the rule for this controller.
 */
class CheckInOnboarding extends Controller
{
	public function getShouldShowAction(): bool
	{
		return (new CheckInOnboardingProvider())->getShouldShow(
			(int)CurrentUser::get()->getId()
		);
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function registerShownAction(): void
	{
		(new CheckInOnboardingProvider())->registerShown(
			(int)CurrentUser::get()->getId()
		);
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function enableAllAction(): bool
	{
		(new CheckInOnboardingProvider())->enableAll();

		return true;
	}
}
