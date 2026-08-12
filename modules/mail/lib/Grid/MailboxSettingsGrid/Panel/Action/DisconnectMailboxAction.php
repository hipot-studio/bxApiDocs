<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class DisconnectMailboxAction extends JsPanelAction
{
	public static function getId(): string
	{
		return 'disconnectMailbox';
	}

	public function getName(): string
	{
		return Loc::getMessage('MAIL_MAILBOX_PANEL_ACTION_DISCONNECT_TITLE') ?? '';
	}
}
