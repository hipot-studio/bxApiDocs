<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Im\V2\Application\Features as ImFeatures;
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

	public static function isPasswordlessConnectAvailable(): bool
	{
		return true;
	}

	public static function isMailboxConnectionRequestAvailable(): bool
	{
		return Loader::includeModule('im')
			&& ImFeatures::isMessageBuilderAvailable()
		;
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
