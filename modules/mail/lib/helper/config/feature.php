<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Config\Option;

class Feature
{
	public static function isMailboxGridAvailable(): bool
	{
		return true;
	}

	public static function isCrmAvailable(): bool
	{
		return MailAccess::hasCurrentUserAdminAccess()
			|| Option::get('intranet', 'allow_external_mail_crm', 'Y', SITE_ID) === 'Y';
	}
}
