<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\Main\Application;

/**
 * The only place holding the outgoing addresses of the self-hosted feature. Every address depends on the sales
 * area, so it is kept as a region map resolved by region groups, the way the licensing layer keeps its own links.
 */
final class LicenseLinks
{
	/**
	 * One sales area, so one address and no map. It is the same area the switch of the mode allows - see
	 * `SupersetHostMode::ALLOWED_REGIONS`.
	 */
	private const URL_EXTENSION_PURCHASE = 'https://www.1c-bitrix.ru/buy/products/b24.php#tab-section-10';

	private const SALES_REGION = 'ru';

	/**
	 * Outside the CIS area the product has no Enterprise request section, so the store license keys page is used:
	 * the same one the limit lock component offers.
	 */
	private const URL_ENTERPRISE_PURCHASE = [
		'com' => 'https://store.bitrix24.com/profile/license-keys.php',
		'eu' => 'https://store.bitrix24.com/profile/license-keys.php',
		'de' => 'https://store.bitrix24.de/profile/license-keys.php',
		'ru' => 'https://www.1c-bitrix.ru/buy/products/b24.php#tab-section-1',
		'by' => 'https://www.1c-bitrix.by/buy/products/b24.php#tab-section-1',
		'kz' => 'https://www.1c-bitrix.kz/buy/products/b24.php#tab-section-1',
	];

	/**
	 * The course of the deployment guide is published for the CIS area only.
	 */
	private const URL_DEPLOY_GUIDE_CIS =
		'https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=48&LESSON_ID=33308';

	/**
	 * Null outside the sales area: there is nowhere to apply, and no call to action is shown without an address.
	 */
	public static function getExtensionPurchaseUrl(): ?string
	{
		$region = (string)Application::getInstance()->getLicense()->getRegion();

		return $region === self::SALES_REGION ? self::URL_EXTENSION_PURCHASE : null;
	}

	public static function getEnterprisePurchaseUrl(): string
	{
		$region = (string)Application::getInstance()->getLicense()->getRegion();

		if (in_array($region, ['ru', 'by', 'kz', 'de'], true))
		{
			return self::URL_ENTERPRISE_PURCHASE[$region];
		}

		if (in_array($region, ['eu', 'fr', 'pl', 'it', 'uk'], true))
		{
			return self::URL_ENTERPRISE_PURCHASE['eu'];
		}

		return self::URL_ENTERPRISE_PURCHASE['com'];
	}

	/**
	 * Null outside the CIS sales area, where the guide is not published.
	 */
	public static function getDeployGuideUrl(): ?string
	{
		return Application::getInstance()->getLicense()->isCis()
			? self::URL_DEPLOY_GUIDE_CIS
			: null
		;
	}

	/**
	 * Not kept here: the licensing layer resolves the renewal address by sales area itself, and a copy goes stale.
	 */
	public static function getBoxRenewalUrl(): string
	{
		return Application::getInstance()->getLicense()->getRenewalLink();
	}
}
