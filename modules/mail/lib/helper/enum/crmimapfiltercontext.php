<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

/**
 * What the user was doing when the CRM gate was synced: the failure is reported in these words.
 */
enum CrmImapFilterContext
{
	case MailboxConnect;
	case SettingsSave;
}
