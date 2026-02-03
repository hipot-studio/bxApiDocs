<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\License\Market;
use Bitrix\Call\Settings;
use Bitrix\AI\Engine;
use Bitrix\AI\Quality;
use Bitrix\AI\Tuning\Type;
use Bitrix\AI\Tuning\Manager;
use Bitrix\AI\Tuning\Defaults;


class CallAISettings
{
	public const
		CALL_COPILOT_ENABLE = 'call_copilot_enable',

		CALL_COPILOT_FEATURE_NAME = 'call_copilot',
		CALL_COPILOT_AUTOSTART_FEATURE_NAME = 'call_copilot_autostart',

		TRANSCRIBE_CALL_RECORD_ENGINE = 'transcribe_track',
		TRANSCRIPTION_OVERVIEW_ENGINE = 'resume_transcription',
		TRANSCRIPTION_OVERVIEW_QUALITY = 'meeting_processing'
	;

	public const
		CALL_COPILOT_MARKET_SLIDER_CODE = 'limit_copilot_follow_up',
		CALL_COPILOT_HELP_SLIDER_CODE = 'limit_copilot_follow_up',
		CALL_COPILOT_DISCLAIMER_ARTICLE = [
			'CIS' => '20412666',
			'WEST' => '25775495',
		]
	;

	private const
		AI_FEATURE_NAME = 'ai_available_by_version',
		AI_BOX_AGREEMENT_CODE = 'AI_BOX_AGREEMENT'
	;

	private const
		CALL_RECORD_MIN_USERS = ['B24' => ['ru' => 3, 'def' => 4], 'BOX' => 0],
		CALL_RECORD_MIN_LENGTH = 59
	;


	public static function isCallAIEnable(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled =
				Settings::isAIServiceEnabled()
				&& self::isTariffAvailable()
				&& self::isEnableBySettings()
			;
		}

		return $enabled;
	}

	public static function isEnableBySettings(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled = false;
			if (Loader::includeModule('ai'))
			{
				$settingItem = (new Manager)->getItem(self::CALL_COPILOT_ENABLE);
				if (isset($settingItem) && $settingItem->getValue() === true)
				{
					$enabled = true;
				}
			}
		}

		return $enabled;
	}

	/**
	 * Check if AI agreement has been accepted.
	 * @param int|null $userId
	 * @return bool
	 */
	public static function isAgreementAccepted(?int $userId = null): bool
	{
		if (Loader::includeModule('ai'))
		{
			// box
			if (\Bitrix\AI\Facade\Bitrix24::shouldUseB24() === false)
			{
				$userId = $userId ?? CurrentUser::get()->getId();

				return \Bitrix\AI\Agreement::get(self::AI_BOX_AGREEMENT_CODE)?->isAcceptedByUser((int)$userId) ?? false;
			}

			// b24
			return \Bitrix\AI\Facade\Bitrix24::isFeatureEnabled(self::AI_FEATURE_NAME);
		}

		return false;
	}

	public static function isTariffAvailable(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled = false;
			if (!ModuleManager::isModuleInstalled('bitrix24'))
			{
				// box
				$enabled = Loader::includeModule('ai');
			}

			// b24
			elseif (Loader::includeModule('bitrix24'))
			{
				$enabled = Feature::isFeatureEnabled(self::CALL_COPILOT_FEATURE_NAME);
				if ($enabled && self::checkMarketSubscription())
				{
					$enabled = false;
					if (self::isPaidTariff())
					{
						if (self::isMarketSubscriptionEnabled())
						{
							$enabled = true;
						}
						else
						{
							$enabled = CallAIBaasService::checkQueryLimit();
						}
					}
				}
			}
		}

		return $enabled;
	}

	//region Autostart recoding

	/**
	 * Enable checking market subscription for autostart recoding feature only for ru region.
	 * @todo Remove option check in future
	 * @return bool
	 */
	public static function checkMarketSubscription(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled =
				Application::getInstance()->getLicense()->getRegion() === 'ru'
				&& (bool)Option::get('call', 'market_subscription', false)
			;
		}

		return $enabled;
	}

	/**
	 * Checks market subscription.
	 * @return bool
	 */
	public static function isMarketSubscriptionEnabled(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled =
				Loader::includeModule('bitrix24')
				&& (Market::isPaidVersion() || Market::isTrialVersion()); // market
		}

		return $enabled;
	}

	/**
	 * Checks paid tariff.
	 * @return bool
	 */
	private static function isPaidTariff(): bool
	{
		static $value;
		if ($value === null)
		{
			$value = false;
			if (Loader::includeModule('bitrix24'))
			{
				$value =
					\CBitrix24::IsLicensePaid()
					|| \CBitrix24::IsNfrLicense()
					|| \CBitrix24::IsDemoLicense()
				;
			}
		}
		return $value;
	}

	/**
	 * Checks paid tariff.
	 * @return bool
	 */
	private static function isFreeTariff(): bool
	{
		static $value;
		if ($value === null)
		{
			$value = false;
			if (Loader::includeModule('bitrix24'))
			{
				$value = \CBitrix24::isFreeLicense();
			}
		}
		return $value;
	}

	/**
	 * Method allows to autostart recoding.
	 * @return bool
	 */
	public static function isAutoStartRecordingEnable(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			if (!ModuleManager::isModuleInstalled('bitrix24'))
			{
				// box
				$enabled = (self::getRecordMinUsers() > 0);
			}
			else
			{
				// b24
				$enabled = self::isCopilotAutostartFeatureEnable();
			}
		}

		return $enabled;
	}

	/**
	 * Returns slider code for market subscription promotion.
	 * @return string
	 */
	public static function getMarketSliderCode(): string
	{
		return self::CALL_COPILOT_MARKET_SLIDER_CODE;
	}

	/**
	 * Checks autostart recoding feature.
	 * @return bool
	 */
	public static function isCopilotAutostartFeatureEnable(): bool
	{
		static $enabled;
		if ($enabled === null)
		{
			$enabled = false;

			// b24
			if (Loader::includeModule('bitrix24'))
			{
				$enabled = Feature::isFeatureEnabled(self::CALL_COPILOT_AUTOSTART_FEATURE_NAME);
				if ($enabled && self::checkMarketSubscription())
				{
					$enabled = self::isMarketSubscriptionEnabled(); // market
				}
			}
		}

		return $enabled;
	}

	/**
	 * Returns minimum users in a call to auto start AI processing.
	 * @return int
	 */
	public static function getRecordMinUsers(): int
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			if (self::checkMarketSubscription())
			{
				$region = Application::getInstance()->getLicense()->getRegion() ?? '';
				$defaultValue = match ($region)
				{
					'ru' => self::CALL_RECORD_MIN_USERS['B24']['ru'],
					default => self::CALL_RECORD_MIN_USERS['B24']['def'],
				};
			}
			else
			{
				$defaultValue = self::CALL_RECORD_MIN_USERS['B24']['def'];
			}
		}
		else
		{
			$defaultValue = self::CALL_RECORD_MIN_USERS['BOX'];
		}

		return (int)Option::get('call', 'call_record_min_users', $defaultValue);
	}

	/**
	 * Minimum record length in seconds for AI to start processing.
	 * @return int
	 */
	public static function getRecordMinDuration(): int
	{
		return (int)Option::get('call', 'call_record_min_length', self::CALL_RECORD_MIN_LENGTH);
	}

	public static function getFeedBackLink(): string
	{
		return Option::get('call', 'call_ai_feedback_link', '');
	}

	public static function getHelpSliderCode(): string
	{
		return Option::get('call', 'call_ai_help_code', self::CALL_COPILOT_HELP_SLIDER_CODE);
	}

	public static function getHelpUrl(): string
	{
		Loader::includeModule('ui');
		$url = (new \Bitrix\UI\Helpdesk\Url())->getByCodeArticle(self::CALL_COPILOT_HELP_SLIDER_CODE);

		return $url->getLocator();
	}

	public static function getAgreementUrl(): string
	{
		return '/online/?AI_UX_TRIGGER=box_agreement';
	}

	public static function getDisclaimerArticleCode(): string
	{
		$isCis =
			Application::getInstance()->getLicense()->isCis()
			|| Loc::getCurrentLang() === 'ru'
		;

		return $isCis
			? self::CALL_COPILOT_DISCLAIMER_ARTICLE['CIS']
			: self::CALL_COPILOT_DISCLAIMER_ARTICLE['WEST']
		;
	}

	public static function getDisclaimerUrl(): string
	{
		$article = self::getDisclaimerArticleCode();
		Loader::includeModule('ui');
		$url = (new \Bitrix\UI\Helpdesk\Url())->getByCodeArticle($article);

		return $url->getLocator();
	}

	public static function isDebugEnable(): bool
	{
		return !empty(Option::get('call', 'call_debug_chats', ''));
	}

	public static function isLoggingEnable(): bool
	{
		return (bool)Option::get('call', 'call_log', false);
	}

	/**
	 * @see \Bitrix\AI\Tuning\Manager::loadExternal
	 * @event `ai:onTuningLoad`
	 * @return EventResult
	 */
	public static function onTuningLoad(): EventResult
	{
		$result = new EventResult;

		if (!Settings::isAIServiceEnabled())
		{
			return $result;
		}

		$items = [];
		$groups = [];
		if (!empty(Engine::getListAvailable('call'))) /** @see \Bitrix\AI\Engine::CATEGORIES */
		{
			$groups['call_copilot'] = [
				'title' => Loc::getMessage('CALL_SETTINGS_COPILOT_GROUP'),
				'description' => Loc::getMessage('CALL_SETTINGS_COPILOT_DESCRIPTION'),
				//todo: Add 'helpdesk' article here
			];

			$items[self::CALL_COPILOT_ENABLE] = [
				'group' => 'call_copilot',
				'title' => Loc::getMessage('CALL_SETTINGS_COPILOT_TITLE'),
				'header' => Loc::getMessage('CALL_SETTINGS_COPILOT_HEADER'),
				'type' => Type::BOOLEAN,
				'default' => true,
			];

			$items[self::TRANSCRIBE_CALL_RECORD_ENGINE] = array_merge(
				[
					'group' => 'call_copilot',
					'title' => Loc::getMessage('CALL_SETTINGS_COPILOT_PROVIDER_TRANSCRIBE'),
				],
				Defaults::getProviderSelectFieldParams('call') /** @see \Bitrix\AI\Engine::CATEGORIES */
			);

			$quality = new Quality([
				Quality::QUALITIES[self::TRANSCRIPTION_OVERVIEW_QUALITY]
			]);

			$items[self::TRANSCRIPTION_OVERVIEW_ENGINE] = array_merge(
				[
					'group' => 'call_copilot',
					'title' => Loc::getMessage('CALL_SETTINGS_COPILOT_PROVIDER_RESUME'),
				],
				Defaults::getProviderSelectFieldParams('text', $quality) /** @see \Bitrix\AI\Engine::CATEGORIES */
			);
		}

		$result->modifyFields([
			'items' => $items,
			'groups' => $groups,
			'itemRelations' => [
				'call_copilot' => [
					self::CALL_COPILOT_ENABLE => [
						self::TRANSCRIBE_CALL_RECORD_ENGINE,
						self::TRANSCRIPTION_OVERVIEW_ENGINE,
					],
				],
			],
		]);

		return $result;
	}

	/**
	 * @return bool
	 */
	public static function isB24Mode(): bool
	{
		if (Loader::includeModule('ai') && \Bitrix\AI\Facade\Bitrix24::shouldUseB24() === true)
		{
			return true;
		}
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return true;
		}

		return false;
	}

	public static function checkAIAvailabilityInCall(): Result
	{
		$error = null;
		if (!Settings::isAIServiceEnabled())
		{
			$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR, 'AI service is unavailable');
		}
		elseif (!Loader::includeModule('ai'))
		{
			$error = new CallAIError(CallAIError::AI_MODULE_ERROR,  'AI service is unavailable');
		}
		elseif (!self::isTariffAvailable())
		{
			$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR, 'AI service is unavailable');
		}
		elseif (!self::isEnableBySettings())
		{
			$error = new CallAIError(CallAIError::AI_SETTINGS_ERROR, 'AI service is disabled by settings');
		}
		elseif (!self::isAgreementAccepted())
		{
			$error = new CallAIError(CallAIError::AI_AGREEMENT_ERROR, 'AI service agreement must be accepted');
		}
		elseif (self::checkMarketSubscription())
		{
			if (self::isFreeTariff())
			{
				//$error = new CallAIError(CallAIError::AI_MARKET_SUBSCRIPTION, 'AI service is unavailable on current subscription plan');
				$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR, 'AI service is unavailable on current subscription plan');
			}
			elseif (self::isPaidTariff() && !self::isMarketSubscriptionEnabled() && !CallAIBaasService::checkQueryLimit())
			{
				//$error = new CallAIError(CallAIError::AI_MARKET_SUBSCRIPTION, 'AI service is unavailable on current subscription plan');
				$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR, 'AI service is unavailable on current subscription plan');
			}
		}

		$result = new Result();
		if ($error !== null)
		{
			$result->addError($error);
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public static function baasAvailable(): bool
	{
		if (!self::checkMarketSubscription())
		{
			return true;
		}
		if (self::isMarketSubscriptionEnabled())
		{
			return true;
		}
		static $enabled;
		if ($enabled === null)
		{
			$enabled = CallAIBaasService::checkQueryLimit();
		}
		return $enabled;
	}

	/**
	 * todo Remove  method check in future
	 * @deprecated
	 * @return bool
	 */
	public static function isBaasServiceHasPackage(): bool
	{
		return self::baasAvailable();
	}
}
