<?php

use Bitrix\Main;
use Bitrix\MailService\Public\Service\MailhostExchange;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	return;
}

class CMailClientInvitationComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		global $USER;

		$mailboxId = Main\Context::getCurrent()->getRequest()->getQuery('mailboxId');
		$authorized = is_object($USER) && $USER->isAuthorized() && (int)$USER->getId() > 0;
		$this->arResult['MAILBOX_ID'] = '';
		if (
			$authorized
			&& is_string($mailboxId)
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $mailboxId)
			&& Main\Loader::includeModule('mailservice')
			&& class_exists(MailhostExchange::class)
			&& (new MailhostExchange())->isEnabled()
		)
		{
			$this->arResult['MAILBOX_ID'] = $mailboxId;
		}

		$this->includeComponentTemplate();
	}
}
