<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class Feature
{

	public static function isMailboxGridAvailable(): bool
	{
		return true;
	}

	public static function isMailboxGridBulkActionsAvailable(): bool
	{
		return Option::get('mail', 'enable_list_improvements', 'N') === 'Y';
	}

	public static function isPasswordlessConnectAvailable(): bool
	{
		return true;
	}

	public static function isMailboxConnectionRequestAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public static function isMailboxConfigRedesignAvailable(): bool
	{
		return true;
	}

	public static function isMailboxOwnerChangeAvailable(): bool
	{
		return true;
	}

	public static function isCrmAvailable(): bool
	{
		return MailAccess::hasCurrentUserAdminAccess()
			|| Option::get('intranet', 'allow_external_mail_crm', 'Y', SITE_ID) === 'Y'
		;
	}

	public static function isUnlimitedMailSyncPeriodAvailable(): bool
	{
		return !ModuleManager::isModuleInstalled('bitrix24');
	}
}
