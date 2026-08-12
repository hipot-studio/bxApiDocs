<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class DisableCrmAction extends JsPanelAction
{
	public static function getId(): string
	{
		return 'disableCrm';
	}

	public function getName(): string
	{
		return Loc::getMessage('MAIL_MAILBOX_PANEL_ACTION_CRM_DISABLE_TITLE') ?? '';
	}
}
