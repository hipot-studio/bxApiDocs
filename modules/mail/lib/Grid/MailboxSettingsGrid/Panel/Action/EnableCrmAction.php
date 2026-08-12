<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class EnableCrmAction extends JsPanelAction
{
	public static function getId(): string
	{
		return 'enableCrm';
	}

	public function getName(): string
	{
		return Loc::getMessage('MAIL_MAILBOX_PANEL_ACTION_CRM_ENABLE_TITLE') ?? '';
	}
}
