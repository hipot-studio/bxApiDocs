<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class DisableCalendarAction extends JsPanelAction
{
	public static function getId(): string
	{
		return 'disableCalendar';
	}

	public function getName(): string
	{
		return Loc::getMessage('MAIL_MAILBOX_PANEL_ACTION_CALENDAR_DISABLE_TITLE') ?? '';
	}
}
