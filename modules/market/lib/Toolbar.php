<?php

namespace Bitrix\Market;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Market\Subscription\Status;
use Bitrix\Rest\Marketplace\Client;
use CRestUtil;

class Toolbar
{
	public static function getInfo($marketAction, $searchAction): array
	{
		$result = [
			'CATEGORIES' => Categories::forceGet(),
			'FAV_NUMBERS' => count(AppFavoritesTable::getUserFavorites()),
			'MENU_INFO' => Menu::getList(),
			'MARKET_SLIDER' => Status::getSlider(),
			'MARKET_ACTION' => $marketAction,
			'SEARCH_ACTION' => $searchAction,
		];

		if (CRestUtil::isAdmin()) {
			$result['NUM_UPDATES'] = Client::getAvailableUpdateNum();
		}

		return $result;
	}

	public static function getTotalAppsInfo(array $totalAppsResponse, ?string $licenseRegion = null): array
	{
		$licenseRegion ??= self::getLicenseRegion();
		$marketLogoTitle = self::resolveMarketLogoTitle(
			$totalAppsResponse['MARKET_LOGO_TITLE'] ?? '',
			$licenseRegion,
		);
		$marketToolbarTitle = self::resolveMarketToolbarTitle(
			$totalAppsResponse['MARKET_TOOLBAR_TITLE'] ?? '',
			$licenseRegion,
		);

		return [
			'TOTAL_APPS' => NumberApps::get($totalAppsResponse),
			'SHOW_MARKET_ICON' => $totalAppsResponse['SHOW_MARKET_ICON'] ?? '',
			'ADDITIONAL_CONTENT' => $totalAppsResponse['ADDITIONAL_CONTENT'] ?? '',
			'ADDITIONAL_MARKET_ACTION' => $totalAppsResponse['ADDITIONAL_MARKET_ACTION'] ?? '',
			'ADDITIONAL_SEARCH_ACTION' => $totalAppsResponse['ADDITIONAL_SEARCH_ACTION'] ?? '',
			'ADDITIONAL_HIT_ACTION' => $totalAppsResponse['ADDITIONAL_HIT_ACTION'] ?? '',
			'SEARCH_FILTERS' => self::prepareSearchFilters($totalAppsResponse['MARKET_SEARCH_FILTERS'] ?? []),
			'MARKET_LOGO_TITLE' => $marketLogoTitle,
			'MARKET_TOOLBAR_TITLE' => $marketToolbarTitle,
			'MARKET_NAME_MESSAGE_CODE' => $totalAppsResponse['MARKET_NAME_MESSAGE_CODE'] ?? '',
		];
	}

	public static function getMarketPageTitle(?string $licenseRegion = null): string
	{
		return self::resolveMarketLogoTitle(
			Loc::getMessage('MARKET_MAIN_PAGE_TITLE_MSGVER_1') ?? '',
			$licenseRegion ?? self::getLicenseRegion(),
		);
	}

	private static function resolveMarketLogoTitle(string $marketLogoTitle, ?string $licenseRegion): string
	{
		if (!self::isBelarusLicenseRegion($licenseRegion)) {
			return $marketLogoTitle;
		}

		return Loc::getMessage('MARKET_MAIN_PAGE_TITLE_BY') ?? $marketLogoTitle;
	}

	private static function resolveMarketToolbarTitle(string $marketToolbarTitle, ?string $licenseRegion): string
	{
		if (!self::isBelarusLicenseRegion($licenseRegion)) {
			return $marketToolbarTitle;
		}

		return Loc::getMessage('MARKET_PLUS_TITLE_BY') ?? $marketToolbarTitle;
	}

	private static function isBelarusLicenseRegion(?string $licenseRegion): bool
	{
		return $licenseRegion === 'by';
	}

	private static function getLicenseRegion(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}

	private static function prepareSearchFilters($searchFilters): array
	{
		if (!is_array($searchFilters) || !is_array($searchFilters['LIST'] ?? null)) {
			return [];
		}

		$namedItems = array_values(array_filter(
			$searchFilters['LIST'],
			static fn($item): bool => is_array($item) && is_string($item['NAME'] ?? null) && $item['NAME'] !== '',
		));

		if (empty($namedItems)) {
			return [];
		}

		$searchFilters['LIST'] = $namedItems;

		return $searchFilters;
	}
}
