<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot;

use Bitrix\AI\Tuning;
use Bitrix\AiAssistant\Config;
use Bitrix\Bitrix24\Feature;
use Bitrix\Landing;
use Bitrix\Landing\Public\Features\AiSitesMarkupFlag;
use Bitrix\Main\Config\Feature as MainFeature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Client;

/**
 * Some functions for AI sites
 */
class Manager
{
	public const AI_SITES_MODE_LEGACY = 'legacy';
	public const AI_SITES_MODE_MARKUP = 'markup';

	private const OPTION_AI_SITES_ENABLED = 'landing_ai_sites_enabled';
	private const OPTION_AI_SITE_SELECTED_ELEMENT_ENABLED = 'landing_ai_site_selected_element_enabled';
	private const OPTION_AI_SITE_FLUX_IMAGE_ENABLED = 'landing_ai_site_flux_image_enabled';

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

	public static function isAiSitesEnabled(): bool
	{
		return Option::get('landing', self::OPTION_AI_SITES_ENABLED, 'N', '') === 'Y'
			&& self::isAiAssistantAiSitesAvailable()
		;
	}

	/**
	 * Selected AI sites generation by the state of the portal Feature flag: enabled selects markup,
	 * disabled selects legacy. Pure selection - the common gate, tariff and site format are not checked
	 * here. The flag is read without a user, so user rules do not split the portal. The value is not
	 * cached in this class: every new request sees the current state.
	 */
	public static function getAiSitesMode(): string
	{
		return MainFeature::isEnabled(AiSitesMarkupFlag::class, new Context())
			? self::AI_SITES_MODE_MARKUP
			: self::AI_SITES_MODE_LEGACY
		;
	}

	/**
	 * Effective legacy gate. Derived from the selected mode, not from a negated markup gate:
	 * otherwise a closed common gate would open legacy.
	 */
	public static function isAiSitesLegacyEnabled(): bool
	{
		return self::getAiSitesMode() === self::AI_SITES_MODE_LEGACY
			&& self::isAiSitesEnabled()
		;
	}

	/**
	 * Effective markup (custom code) gate: the markup mode alone never enables anything
	 * without the common gate.
	 */
	public static function isAiSitesMarkupEnabled(): bool
	{
		return self::getAiSitesMode() === self::AI_SITES_MODE_MARKUP
			&& self::isAiSitesEnabled()
		;
	}

	/**
	 * Compatibility alias of the markup gate; the former create sub-option is not read.
	 */
	public static function isAiSitesMarkupCreateEnabled(): bool
	{
		return self::isAiSitesMarkupEnabled();
	}

	/**
	 * Compatibility alias of the markup gate; the former change sub-option is not read.
	 */
	public static function isAiSitesMarkupChangeEnabled(): bool
	{
		return self::isAiSitesMarkupEnabled();
	}

	public static function getAiSitesDisabledErrorCode(): string
	{
		return 'ai_sites_disabled_by_option';
	}

	public static function isAiSiteSelectedElementEditEnabled(): bool
	{
		return Option::get('landing', self::OPTION_AI_SITE_SELECTED_ELEMENT_ENABLED, 'N', '') === 'Y';
	}

	/**
	 * Is FLUX image engine enabled for AI sites.
	 *
	 * @return bool
	 */
	public static function isFluxImageEnabled(): bool
	{
		return Option::get('landing', self::OPTION_AI_SITE_FLUX_IMAGE_ENABLED, 'N', '') === 'Y';
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

	private static function isAiAssistantAiSitesAvailable(): bool
	{
		return Loader::includeModule('aiassistant')
			&& Config\Feature::getInstance()->isBitrixGptV2Available()
			&& Config\Feature::getInstance()->isCopilotTriggersEnabled()
		;
	}
}
