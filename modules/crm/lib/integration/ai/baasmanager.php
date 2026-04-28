<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\AI\Integration\Baas\BaasTokenService;
use Bitrix\Crm\Integration\Rest\Marketplace\Client;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectNotFoundException;

final class BaasManager
{
	public const SLIDER_CODE_LIMIT_DAILY = 'limit_copilot_max_number_daily_requests';
	public const SLIDER_CODE_LIMIT_MONTHLY = 'limit_copilot_requests';

	private const AI_IGNORE_BAAS = 'AI_IGNORE_BAAS';
	private const SLIDER_CODE_BUY_MARKET = 'limit_subscription_market_access_buy_marketplus';
	private const SLIDER_CODE_EMPTY_MARKET_PACKAGES = 'limit_subscription_market_ai_spent';
	private const SLIDER_CODE_EMPTY_BAAS_PACKAGES = 'limit_boost_copilot'; // check slider code for call "limit_boost_crm_automation";

	/**
	 * Method checks if BAAS connection is available.
	 *
	 * @return bool
	 *
	 * @throws ObjectNotFoundException
	 */
	public static function isAvailable(): bool
	{
		if (self::isIgnored())
		{
			return true;
		}

		return self::isUsable() && self::getTokenService()->isMarketAvailable();
	}

	/**
	 * Method checks available tokens when market subscription enabled.
	 *
	 * @return bool
	 *
	 * @throws ObjectNotFoundException
	 */
	public static function hasPackage(): bool
	{
		if (self::isIgnored())
		{
			return true;
		}

		if (!self::isMarketSubscriptionEnabled())
		{
			return false;
		}

		return self::isUsable() && self::getTokenService()->canConsume();
	}

	/**
	 * Method return promo slider codes when no packages.
	 *
	 * @return string
	 *
	 * @throws ObjectNotFoundException
	 */
	public static function getEmptyPackagesSliderCode(): string
	{
		if (self::isMarketSubscriptionEnabled())
		{
			return self::isAvailable()
				? self::SLIDER_CODE_EMPTY_MARKET_PACKAGES
				: self::SLIDER_CODE_EMPTY_BAAS_PACKAGES
				;
		}

		return self::SLIDER_CODE_BUY_MARKET;
	}

	/**
	 * Method get BAAS settings.
	 *
	 * @return array
	 *
	 * @throws ObjectNotFoundException
	 */
	public static function getSettings(): array
	{
		return [
			'isAvailable' => self::isAvailable(),
			'hasPackage' => self::hasPackage(),
			'aiPackagesEmptySliderCode' => self::getEmptyPackagesSliderCode(),
		];
	}

	/**
	 * Method for temporary ignoring Baas connection.
	 * It can be used if Baas is unavailable for some reason, but we still want to provide AI features in CRM.
	 *
	 * @return bool
	 */
	public static function isIgnored(): bool
	{
		return (bool)Option::get('crm', self::AI_IGNORE_BAAS, false);
	}

	/**
	 * Set Baas ignoring flag.
	 *
	 * @param bool $flag
	 *
	 * @return void
	 *
	 * @throws ArgumentOutOfRangeException
	 */
	public static function setIgnored(bool $flag): void
	{
		Option::set('crm', self::AI_IGNORE_BAAS, $flag);
	}

	private static function isUsable(): bool
	{
		return AIManager::isAvailable() && Loader::includeModule('baas');
	}

	private static function getTokenService(): BaasTokenService
	{
		$service = ServiceLocator::getInstance()->get(BaasTokenService::class);
		if (!$service instanceof BaasTokenService)
		{
			throw new ObjectNotFoundException('BaasTokenService is not registered');
		}

		return $service;
	}

	private static function isMarketSubscriptionEnabled(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled = (new Client())->isSubscriptionAvailable();
		}

		return $enabled;
	}
}
