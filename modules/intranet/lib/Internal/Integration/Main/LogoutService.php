<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\UserAuthActionTable;

class LogoutService
{
	public function __construct(
		private readonly User $user,
	) {
	}

	public function logoutAll(): void
	{
		(new ApplicationPasswordService())->removeAllByUserId((int)$this->user->getId());

		if ($this->user->isCurrent())
		{
			global $USER;
			$USER->SetParam("AUTH_ACTION_SKIP_LOGOUT", true);
		}

		UserAuthActionTable::addLogoutAction($this->user->getId());
	}
}
