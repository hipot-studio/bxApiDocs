<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot;

use Bitrix\AI\Tuning;
use Bitrix\Bitrix24\Feature;
use Bitrix\Landing;
use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Client;

/**
 * Some functions for AI sites
 */
class Manager
{
	/**
	 * Check is feature available (by option, tariff etc.)
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool
	{
		if (Landing\Manager::getZone() === 'cn')
		{
			return false;
		}

		return Loader::includeModule('ai');
	}

	/**
	 * Checks whether the feature is enabled.
	 *
	 * The feature is considered enabled if:
	 * - it is allowed by the current tariff / subscription, or
	 * - it is allowed as a special case for the first site generation.
	 *
	 * @return bool
	 */
	public static function isFeatureEnabled(): bool
	{
		$isEnabledByTariff = self::isFeatureEnabledByLicense();

		if (
			$isEnabledByTariff === false
			&& Landing\Copilot\Services\FirstSiteGenerationService::isFirstSiteGeneration() === true
		)
		{
			$isEnabledByTariff = true;
		}

		return $isEnabledByTariff;
	}

	/**
	 * Checks whether the feature is enabled.
	 *
	 * The feature is considered enabled if it is allowed by the current tariff / subscription
	 *
	 * @return bool
	 */
	public static function isFeatureEnabledByLicense(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			if (Loader::includeModule('rest') && Client::isSubscriptionAccess())
			{
				if (Client::isSubscriptionAvailable())
				{
					return true;
				}

				return false;
			}

			return Feature::isFeatureEnabled('landing_allow_ai_sites');
		}

		return true;
	}

	/**
	 * Check is feature enabled in settings
	 * @return bool
	 */
	public static function isActive(): bool
	{
		if (!static::isAvailable())
		{
			return false;
		}

		$manager = new Tuning\Manager();
		$item = $manager->getItem(Landing\Connector\Ai::TUNING_CODE_ALLOW_SITE_COPILOT);

		return $item ? $item->getValue() : false;
	}

	/**
	 * Get slider code for feature usage limit
	 *
	 * @return string
	 */
	public static function getLimitSliderCode(): string
	{
		if (Loader::includeModule('rest') && Client::isSubscriptionAccess())
		{
			return Landing\Copilot\Connector\AI\Type\SliderCode::MarketTrial->value;
		}

		return Landing\Copilot\Connector\AI\Type\SliderCode::Copilot->value;
	}

	/**
	 * Get slider code for disabled feature state
	 *
	 * @return string
	 */
	public static function getUnactiveSliderCode(): string
	{
		return Landing\Copilot\Connector\AI\Type\SliderCode::CopilotOff->value;
	}
}
