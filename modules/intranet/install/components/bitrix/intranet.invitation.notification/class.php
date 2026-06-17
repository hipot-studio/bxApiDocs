<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Intranet\Internal\Integration\Bitrix24\License\DemoLicense;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Invitation;
use Bitrix\Intranet\User;
use Bitrix\Main\Loader;

class IntranetInvitationNotification extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return;
		}

		$this->onPrepareComponentResult();

		$this->includeComponentTemplate();
	}

	private function onPrepareComponentResult(): void
	{
		$this->arResult['SHOW_DEMO_POPUP'] =
			\CUserOptions::GetOption('intranet.invitation', 'demoPopupShown', 'N') === 'N'
			&& (new DemoLicense())->isActive()
		;
		$this->arResult['CAN_INVITE'] =
			Invitation::canCurrentUserInvite()
			&& (new User())->isIntranet()
			&& !(new InvitationLimiter())->isExceeded()
		;
	}
}

