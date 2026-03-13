<?php

namespace Bitrix\Intranet\MainPage;

use Bitrix\Bitrix24\Feature;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Landing;
use Bitrix\Main\Loader;
use Bitrix\Intranet;

class Access
{
	public function canEdit(): bool
	{
		return
			$this->canViewAsAdmin()
			&& $this->isAvailableFeature()
		;
	}

	public function canView(): bool
	{
		return
			$this->isAvailable()
			&& $this->checkUserPermissions()
		;
	}

	public function canViewAsAdmin(): bool
	{
		return
			$this->canView()
			&& CurrentUser::get()->isAdmin()
		;
	}

	/**
	 * Check required modules and modules availability
	 * @return bool
	 */
	private function isAvailable(): bool
	{
		return
			Loader::includeModule('landing')
			&& Landing\Mainpage\Manager::isAvailable()
		;
	}

	private function isAvailableFeature(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Feature::isFeatureEnabled('main_page');
		}

		return true;
	}

	private function checkUserPermissions(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return !\CBitrix24::IsExtranetUser(CurrentUser::get()->getId());
		}

		if (Loader::includeModule('intranet'))
		{
			return (new Intranet\User())->isIntranet();
		}

		return false;
	}
}