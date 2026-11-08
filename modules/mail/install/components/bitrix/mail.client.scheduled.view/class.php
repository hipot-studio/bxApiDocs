<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class CMailClientScheduledViewComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		global $USER;

		if (!is_object($USER) || !$USER->isAuthorized() || !Feature::isScheduledMessageAvailable())
		{
			showError(Loc::getMessage('MAIL_SCHEDULED_VIEW_NOT_FOUND'));

			return;
		}

		$this->arResult['SCHEDULED_ID'] = max(0, (int)($this->arParams['VARIABLES']['scheduledId'] ?? 0));
		$this->arResult['MAILBOX_ID'] = max(0, (int)($this->arParams['VARIABLES']['mailboxId'] ?? 0));
		if ($this->arResult['SCHEDULED_ID'] === 0 || $this->arResult['MAILBOX_ID'] === 0)
		{
			showError(Loc::getMessage('MAIL_SCHEDULED_VIEW_NOT_FOUND'));

			return;
		}

		$this->includeComponentTemplate();
	}
}
