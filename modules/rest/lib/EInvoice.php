<?php

namespace Bitrix\Rest;

use Bitrix\Main;
use Bitrix\Rest\Engine\Access;
use Bitrix\Rest\Internal\Service\Application\EInvoice\ApplicationTagResolver;

class EInvoice
{
	public const APP_TAG = 'e-invoice';

	public static function isAvailable(): bool
	{
		return Access::isAvailable() && !Main\Application::getInstance()->getLicense()->isCis();
	}

	public static function getApplicationList(): array
	{
		$cache = Main\Application::getInstance()->getCache();

		if ($cache->initCache(60 * 60 * 24, self::APP_TAG . '_marketplace_v4', '/rest/einvoice/'))
		{
			return $cache->getVars();
		}

		$apps = [];
		foreach ((new ApplicationTagResolver())->getCodes() as $code)
		{
			$apps = array_merge($apps, Marketplace\Client::getByTag([self::APP_TAG, $code])['ITEMS'] ?? []);
		}
		$result = array_values(array_column($apps, null, 'ID'));

		$cache->startDataCache();
		$cache->endDataCache($result);

		return $result;
	}

	public static function getInstalledApplications(): array
	{
		$applicationList = self::getApplicationList();
		$codes = [];

		foreach ($applicationList as $application)
		{
			if (isset($application['CODE']))
			{
				$codes[] = $application['CODE'];
			}
		}

		if (empty($codes))
		{
			return [];
		}

		$result = AppTable::query()
			->whereIn('CODE', $codes)
			->setSelect([
				'*',
				'MENU_NAME' => 'LANG.MENU_NAME',
				'MENU_NAME_DEFAULT' => 'LANG_DEFAULT.MENU_NAME',
				'MENU_NAME_LICENSE' => 'LANG_LICENSE.MENU_NAME',
			])
			->exec();

		return $result->fetchAll();
	}
}
