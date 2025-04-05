<?php

namespace Bitrix\Intranet\MainPage;

use Bitrix\Bitrix24\Feature;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Landing;
use Bitrix\Main\Loader;

class Access
{
	public function canEdit($isCheckFeature = true): bool
	{
		return $this->canView($isCheckFeature) && CurrentUser::get()->isAdmin();
	}

	public function canView($isCheckFeature = true): bool
	{
		if (!$isCheckFeature)
		{
			return
				$this->checkRequiredModules()
				&& $this->checkUserPermissions()
				&& $this->isAvailableByLanding()
			;
		}

		return
			$this->checkRequiredModules()
			&& $this->isAvailableFeature()
			&& $this->checkUserPermissions()
		;
	}

	private function checkRequiredModules(): bool
	{
		return Loader::includeModule('landing');
	}

	private function checkUserPermissions(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return !\CBitrix24::IsExtranetUser(CurrentUser::get()->getId());
		}

		//todo: tmp hiding. Set true for non-bitrix24 at version 25.0.0
		return false;
	}

	private function isAvailableFeature(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Feature::isFeatureEnabled('main_page') && $this->isAvailableByLanding();
		}

		return $this->isAvailableByLanding();
	}

	private function isAvailableByLanding(): bool
	{
		return Loader::includeModule('intranet');
		// tmp hide, open and add version_control to landing 25.0.0
		// return Landing\Mainpage\Manager::isAvailable();
	}
}