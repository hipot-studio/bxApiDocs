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
	 * The landing of the Enterprise edition: the page the client is led to by every call to action of the sales
	 * area, the request for the extension included. It is published in Russian and for the Russian sale only, so
	 * outside that area the addresses stay the regional ones.
	 */
	private const URL_ENTERPRISE_LANDING = 'https://www.bitrix24.ru/enterprise/';

	/**
	 * The section where the extension of the license is renewed. It is kept apart from the landing on purpose: the
	 * popup of the early window leads the administrator straight to the renewal, while every other surface leads to
	 * the page presenting the edition.
	 */
	private const URL_EXTENSION_RENEWAL = 'https://www.1c-bitrix.ru/buy/products/b24.php#tab-section-10';

	/**
	 * One sales area, so one address and no map. It is the same area the switch of the mode allows - see
	 * `SupersetHostMode::ALLOWED_REGIONS`.
	 */
	private const SALES_REGION = 'ru';

	/**
	 * Outside the CIS area the product has no Enterprise request section, so the store license keys page is used:
	 * the same one the limit lock component offers. The sales area itself is not in the map - the landing answers
	 * for it, and a second address for the same region would go stale unnoticed.
	 */
	private const URL_ENTERPRISE_PURCHASE = [
		'com' => 'https://store.bitrix24.com/profile/license-keys.php',
		'eu' => 'https://store.bitrix24.com/profile/license-keys.php',
		'de' => 'https://store.bitrix24.de/profile/license-keys.php',
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
		return self::isSalesRegion() ? self::URL_ENTERPRISE_LANDING : null;
	}

	/**
	 * The address for the one surface led to the renewal itself instead of the landing - the popup that meets the
	 * administrator inside the early window. Null outside the sales area, as every other address of the sale.
	 */
	public static function getExtensionRenewalUrl(): ?string
	{
		return self::isSalesRegion() ? self::URL_EXTENSION_RENEWAL : null;
	}

	/**
	 * Inside the sales area this is the same address as the one for the extension: the landing sells the edition
	 * and its extensions on one page. The two methods stay apart because their callers differ - an absent
	 * extension address takes the call to action away, while the edition always has somewhere to lead.
	 */
	public static function getEnterprisePurchaseUrl(): string
	{
		if (self::isSalesRegion())
		{
			return self::URL_ENTERPRISE_LANDING;
		}

		$region = self::getRegion();

		if (in_array($region, ['by', 'kz', 'de'], true))
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

	private static function getRegion(): string
	{
		return (string)Application::getInstance()->getLicense()->getRegion();
	}

	private static function isSalesRegion(): bool
	{
		return self::getRegion() === self::SALES_REGION;
	}
}
