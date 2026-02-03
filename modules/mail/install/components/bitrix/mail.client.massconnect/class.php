<?php

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\ModuleManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

class CMailClientMassconnectComponent extends CBitrixComponent
{
	public function executeComponent()
	{
		$canManage = MailAccess::hasCurrentUserAccessToMassConnect();

		if (!$canManage)
		{
			showError('access denied');

			return;
		}

		$this->arResult['TITLE'] = Loc::getMessage('MAIL_CLIENT_MASSCONNECT_TITLE_MSGVER_1');
		$this->arResult['IS_SMTP_AVAILABLE'] = $this->isSmtpAvailable();
		$this->includeComponentTemplate();
	}

	private function isSmtpAvailable()
	{
		return ModuleManager::isModuleInstalled('bitrix24') || Configuration::getValue("smtp");
	}
}
