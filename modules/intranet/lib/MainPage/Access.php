<?php

namespace Bitrix\Intranet\MainPage;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Integration;

class Access
{
	public function canEdit(): bool
	{
		$integration = Integration\Landing\Vibe\MainPage::getInstance();

		return isset($integration) && $integration->getVibe()->canEdit();
	}

	public function canView(): bool
	{
		$integration = Integration\Landing\Vibe\MainPage::getInstance();

		return isset($integration) && $integration->getVibe()->canView();
	}

	public function canViewAsAdmin(): bool
	{
		return
			$this->canView()
			&& CurrentUser::get()->isAdmin()
		;
	}
}