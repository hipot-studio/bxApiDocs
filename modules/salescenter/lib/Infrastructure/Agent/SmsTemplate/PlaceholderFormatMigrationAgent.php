<?php

namespace Bitrix\SalesCenter\Infrastructure\Agent\SmsTemplate;

use Bitrix\Crm\Format\PlaceholderFormatter;
use Bitrix\Main\Loader;
use Bitrix\SalesCenter\Integration\CrmManager;
use CCrmOwnerType;

final class PlaceholderFormatMigrationAgent
{
	public static function run(): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		if (!method_exists(PlaceholderFormatter::class, 'convertDisplayToBBCodeFormat'))
		{
			return self::class . '::run();';
		}

		$manager = CrmManager::getInstance();

		foreach ([CrmManager::SMS_MODE_PAYMENT, CrmManager::SMS_MODE_COMPILATION] as $mode)
		{
			self::migrateMode($manager, $mode);
		}

		return '';
	}

	private static function migrateMode(CrmManager $manager, string $mode): void
	{
		$current = $manager->getSmsTemplate($mode);
		$migrated = PlaceholderFormatter::convertDisplayToBBCodeFormat(
			CCrmOwnerType::Deal,
			$current,
		);

		if ($migrated !== $current)
		{
			$manager->saveSmsTemplate($migrated, $mode);
		}
	}
}
