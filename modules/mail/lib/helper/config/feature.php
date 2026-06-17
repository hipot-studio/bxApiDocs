<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;

class Feature
{
	private const MAILBOX_CONNECTION_REQUEST_OPTION = 'mailbox_connection_request_available';

	public static function isMailboxGridAvailable(): bool
	{
		return true;
	}

	public static function isPasswordlessConnectAvailable(): bool
	{
		return true;
	}

	public static function isMailboxConnectionRequestAvailable(): bool
	{
		return Option::get('mail', self::MAILBOX_CONNECTION_REQUEST_OPTION, 'N') === 'Y';
	}

	public static function isCrmAvailable(): bool
	{
		return MailAccess::hasCurrentUserAdminAccess()
			|| Option::get('intranet', 'allow_external_mail_crm', 'Y', SITE_ID) === 'Y';
	}

	public static function isUnlimitedMailSyncPeriodAvailable(): bool
	{
		return !ModuleManager::isModuleInstalled('bitrix24');
	}
}
