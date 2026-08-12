<?php

namespace Bitrix\Market;

use Bitrix\Main\Application;
use Bitrix\Market\Rest\Actions;
use Bitrix\Market\Rest\Transport;

class Categories
{
	private const CACHE_ID = 'rest|market|categories|' . LANGUAGE_ID;
	private const MOBILE_CACHE_ID = self::CACHE_ID . '|mobile';
	private const DESCRIPTION_CACHE_VERSION = 1;
	private const DESCRIPTION_FIELDS = [
		'DESCRIPTION',
		'DESC',
		'SHORT_DESCRIPTION',
		'SHORT_DESC',
		'PREVIEW_TEXT',
		'DETAIL_TEXT',
		'SUMMARY',
		'TEXT',
	];

	private static array $list = [];
	private static array $mobileList = [];

	public static function get(bool $isMobileMarket = false): array
	{
		return $isMobileMarket ? self::$mobileList : self::$list;
	}

	public static function set(array $categories, bool $isMobileMarket = false): void
	{
		if ($isMobileMarket)
		{
			self::$mobileList = self::normalizeCategories($categories);

			return;
		}

		self::$list = self::normalizeCategories($categories);
	}

	public static function initFromCache(bool $isMobileMarket = false): void
	{
		$managedCache = Application::getInstance()->getManagedCache();
		$cacheId = self::getCacheId($isMobileMarket);
		if ($managedCache->read(86400, $cacheId)) {
			$cacheResult = $managedCache->get($cacheId);
			if (is_array($cacheResult)) {
				self::set($cacheResult, $isMobileMarket);
			}
		}
	}

	public static function saveCache(array $result, bool $isMobileMarket = false): void
	{
		if (empty($result)) {
			return;
		}

		$result = self::normalizeCategories($result);
		$result['DESCRIPTION_CACHE_VERSION'] = self::DESCRIPTION_CACHE_VERSION;
		$managedCache = Application::getInstance()->getManagedCache();
		$managedCache->set(self::getCacheId($isMobileMarket), $result);
		self::set($result, $isMobileMarket);
	}

	public static function forceGet(bool $isMobileMarket = false): array
	{
		$categories = self::get($isMobileMarket);
		$cacheVersion = (int)($categories['DESCRIPTION_CACHE_VERSION'] ?? 0);

		if (empty($categories) || $cacheVersion < self::DESCRIPTION_CACHE_VERSION)
		{
			$response = Transport::instance()->call(
				Actions::METHOD_GET_CATEGORIES_V2,
				self::getMarketplaceContextParams($isMobileMarket),
			);
			if (!empty($response)) {
				self::saveCache($response, $isMobileMarket);
			}
		}

		return self::get($isMobileMarket);
	}

	private static function getCacheId(bool $isMobileMarket): string
	{
		return $isMobileMarket ? self::MOBILE_CACHE_ID : self::CACHE_ID;
	}

	private static function getMarketplaceContextParams(bool $isMobileMarket): array
	{
		return $isMobileMarket ? [Actions::PARAM_IS_MOBILE_MARKET => 'Y'] : [];
	}

	private static function normalizeCategories(array $categories): array
	{
		if (isset($categories['ITEMS']) && is_array($categories['ITEMS']))
		{
			$categories['ITEMS'] = array_values(
				array_map(
					static fn(array $category): array => self::normalizeCategory($category),
					$categories['ITEMS'],
				),
			);
		}

		if (isset($categories['FIX_ITEMS']) && is_array($categories['FIX_ITEMS']))
		{
			$categories['FIX_ITEMS'] = array_values(
				array_map(
					static fn(array $category): array => self::normalizeCategory($category),
					$categories['FIX_ITEMS'],
				),
			);
		}

		return $categories;
	}

	private static function normalizeCategory(array $category): array
	{
		$category['DESCRIPTION'] = self::resolveDescription($category);

		return $category;
	}

	private static function resolveDescription(array $category): string
	{
		foreach (self::DESCRIPTION_FIELDS as $fieldName)
		{
			$description = trim((string)($category[$fieldName] ?? ''));

			if ($description !== '')
			{
				return $description;
			}
		}

		return '';
	}
}
