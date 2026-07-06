<?php

namespace Bitrix\Rest;

use Bitrix\Main;
use Bitrix\Rest\Engine\Access;

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
		$cacheId = self::APP_TAG . '_marketplace_v3';
		$cacheTtl = 60 * 60 * 24; // 24 hour
		$cachePath = '/rest/einvoice/';
		$region = mb_strtolower(Main\Application::getInstance()->getLicense()->getRegion());
		$country = mb_strtolower(Main\Config\Option::get('bitrix24', 'REG_COUNTRY', $region));
		$tagsViaRegion = [self::APP_TAG, $region];
		$tagsViaCountry = [self::APP_TAG, $country];

		if ($cache->initCache($cacheTtl, $cacheId, $cachePath))
		{
			$result = $cache->getVars();
		}
		else
		{
			$apps = Marketplace\Client::getByTag($tagsViaRegion)['ITEMS'] ?? [];

			if($country !== $region && !empty($country))
			{
				$apps = array_merge(
					$apps,
					Marketplace\Client::getByTag($tagsViaCountry)['ITEMS'] ?? [],
				);
			}
			$result = [];
			foreach ($apps as $app)
			{
				$result[$app['ID']] = $app;
			}
			$result = array_values($result);

			$cache->startDataCache();
			$cache->endDataCache($result);
		}

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
