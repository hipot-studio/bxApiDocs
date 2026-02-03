<?php

namespace Bitrix\Call;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Im;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIBaasService;

class Settings
{
	public const CALL_CLOUD_RECORDING_ENABLE = 'call_cloud_recording';

	public static function getMobileOptions(): array
	{
		return array_merge([
			'useCustomTurnServer' => Option::get('call', 'turn_server_self') === 'Y',
			'turnServer' => \Bitrix\Im\Call\Call::getTurnServer(),
			'turnServerLogin' => Option::get('call', 'turn_server_login', ''),
			'turnServerPassword' => Option::get('call', 'turn_server_password', ''),
			'callLogService' => Option::get('call', 'call_log_service', ''),
			'sfuServerEnabled' => Im\Call\Call::isCallServerEnabled(),
			'bitrixCallsEnabled' => Im\Call\Call::isBitrixCallEnabled(),
			'callBetaIosEnabled' => Im\Call\Call::isIosBetaEnabled(),
			'isAIServiceEnabled' => static::isAIServiceEnabled(),//todo: Deprecated, should be removed after mobile app update
			'userJwt' => JwtCall::getUserJwt((int)CurrentUser::get()->getId()),
			'callBalancerUrl' => static::getBalancerUrl(),
			'jwtCallsEnabled' => static::isNewCallsEnabled(),
			'jwtInPlainCallsEnabled' => static::isPlainCallsUseNewScheme(),
			'plainCallFollowUpEnabled' => static::isPlainCallFollowUpEnabled(),
			'plainCallCloudRecordingEnabled' => static::isPlainCallCloudRecordingEnabled(),

			'ai' => [
				'serviceEnabled' => static::isAIServiceEnabled(),
				'settingsEnabled' => CallAISettings::isEnableBySettings(),
				'recordingMinUsers' => CallAISettings::getRecordMinUsers(),
				'agreementAccepted' => CallAISettings::isAgreementAccepted(),
				'tariffAvailable' => CallAISettings::isTariffAvailable(),
				'feedBackLink' => CallAISettings::getFeedBackLink(),
				'baasAvailable' => CallAISettings::baasAvailable(),
				'baasPromoSlider' => CallAIBaasService::getBaasSliderCode(),
				'marketSubscriptionEnabled' => CallAISettings::isMarketSubscriptionEnabled(),
				'marketSubscriptionSlider' => CallAISettings::getMarketSliderCode(),
				'helpSlider' => CallAISettings::getHelpSliderCode(),
				'disclaimerArticleCode' => CallAISettings::getDisclaimerArticleCode(),
			],
			'isCloudRecordEnabled' => static::isCloudRecordEnabled(),
			'isCloudRecordTariffEnabled' => static::isCloudRecordingAvailable(),
			'isCloudRecordCisRegion' => static::isCisRegion(),
			'isCloudRecordLogEnabled' => static::isCloudRecordLogEnabled(),
			'isCreateCallButtonEnabled' => static::isCreateCallButtonEnabled(),
		], self::getAdditionalMobileOptions());
	}

	public static function isCloudRecordingAvailable(): bool
	{
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			// box
			return static::isCloudRecordTariffEnabled();
		}

		// b24
		if (Loader::includeModule('bitrix24'))
		{
			return Feature::isFeatureEnabled(self::CALL_CLOUD_RECORDING_ENABLE);
		}

		return false;
	}

	public static function isCisRegion(): bool
	{
		return \Bitrix\Main\Application::getInstance()->getLicense()->isCis();
	}

	// todo should be moved to callmobile along with the rest of the parameters
	protected static function getAdditionalMobileOptions(): array
	{
		Loader::includeModule('im');

		$userId = (int)CurrentUser::get()->getId();
		$usersData = Im\Call\Util::getUsers([$userId]);

		return [
			'currentUserData' => $usersData[$userId],
		];
	}

	public static function isConferenceChatEnabled(): bool
	{
		return (bool)Option::get('call', 'conference_chat_enabled', true);
	}

	/**
	 * Call AI feature is enabled.
	 * @return bool
	 */
	public static function isAIServiceEnabled(): bool
	{
		$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: '';
		if ($region === 'cn')
		{
			return false;
		}

		return (bool)Option::get('call', 'call_ai_enabled');
	}

	public static function useTcpSdp(string $region = ''): string
	{
		if (
			($value = Option::get('call', 'call_use_tcp_sdp', null))
			&& in_array($value, ['N', 'Y'])
		)
		{
			return $value;
		}

		return 'N';
	}

	public static function getBalancerUrl(): string
	{
		return (new BalancerClient())->getServiceUrl();
	}

	//region JWT

	/**
	 * @deprecated
	 */
	public static function registerPortalKey(): bool
	{
		return JwtCall::registerPortal()->isSuccess();
	}

	/**
	 * @deprecated
	 */
	public static function registerPortalKeyAgent(int $retryCount = 1): string
	{
		return JwtCall::registerPortalAgent();
	}

	public static function isNewCallsEnabled(): bool
	{
		$defaultValue = \Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24');

		return
			(bool)Option::get('call', 'call_v2_enabled', $defaultValue)
			&& (self::getPortalId() > 0)
		;
	}

	public static function getPrivateKey(): string
	{
		$privateKey = Option::get('call', 'call_portal_key');

		$cryptoOptions = Configuration::getValue('crypto');
		if (!empty($cryptoOptions['crypto_key']))
		{
			try
			{
				$cipher = new Cipher();
				$privateKey = $cipher->decrypt(base64_decode($privateKey), $cryptoOptions['crypto_key']);
			}
			catch (SecurityException $exception)
			{
			}
		}

		return $privateKey;
	}

	public static function getPortalId(): int
	{
		return (int)Option::get('call', 'call_portal_id', 0);
	}

	public static function isPlainCallsUseNewScheme(): bool
	{
		if ((bool)Option::get('call', 'plain_calls_use_new_scheme', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'plain_calls_use_new_scheme', false);
	}

	public static function isPlainCallFollowUpEnabled(): bool
	{
		if ((bool)Option::get('call', 'plain_call_follow_up_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'plain_call_follow_up_enabled', false);
	}

	public static function isPlainCallCloudRecordingEnabled(): bool
	{
		if ((bool)Option::get('call', 'plain_call_cloud_recording_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'plain_call_cloud_recording_enabled', false);
	}

	/**
	 * @deprecated
	 */
	public static function updateCallV2Availability(bool $isJwtEnabled, bool $isPlainUseJwt, string $callBalancerUrl = '', string $callServerUrl = ''): void
	{
		JwtCall::updateCallV2Availability($isJwtEnabled, $isPlainUseJwt, $callBalancerUrl, $callServerUrl);
	}

	/**
	 * Disable camera of new joined users feature is enabled.
	 * @return bool
	 */
	public static function isDisableCameraNewJoinedUsersFeatureEnabled(): bool
	{
		if (Option::get('call', 'call_disable_camera_new_joined_users_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_disable_camera_new_joined_users_enabled', false);
	}

	/**
	 * Enable/disable logs to Kibana.
	 * @return bool
	 */
	public static function isKibanaLogsEnabled(): bool
	{
		if (Option::get('call', 'call_kibana_logs_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_kibana_logs_enabled', false);
	}

	/**
	 * Disable camera of new joined users feature is enabled.
	 * @return int
	 */
	public static function countDisableCameraNewJoinedUsersFeature(): int
	{
		return Option::get('call', 'call_disable_camera_new_joined_users_count', 4);
	}

	/**
	 * Stream quality control is enabled.
	 * @return bool
	 */
	public static function isStreamQualityFeatureEnabled(): bool
	{
		if (Option::get('call', 'call_stream_quality_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_stream_quality_enabled', false);
	}

	/**
	 * Metrics is enabled.
	 * @return bool
	 */
	public static function isMetricsEnabled(): bool
	{
		if (Option::get('call', 'call_metrics_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_metrics_enabled', false);
	}

	/**
	 * Metrics logs is enabled.
	 * @return bool
	 */
	public static function isMetricsLogsEnabled(): bool
	{
		if (Option::get('call', 'call_metrics_logs_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_metrics_logs_enabled', false);
	}

	/**
	 * Cloud call record enabled
	 * @return bool
	 */
	public static function isCloudRecordEnabled(): bool
	{
		if (Option::get('call', 'call_cloud_record', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_cloud_record', false);
	}


	// TODO: Delete after adding tariffs
	/**
	 * Cloud call record tariff enabled
	 * @return bool
	 */
	public static function isCloudRecordTariffEnabled(): bool
	{
		if (Option::get('call', 'call_cloud_record_tariff', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_cloud_record_tariff', false);
	}

	// TODO: Delete after testing
	/**
	 * Cloud call record tariff enabled
	 * @return bool
	 */
	public static function isCloudRecordLogEnabled(): bool
	{
		if (Option::get('call', 'call_cloud_record_log', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_cloud_record_log', false);
	}

	// TODO: Remove empty plug
	/**
	 * Create Call Button is enabled.
	 * @return bool
	 */
	public static function isCreateCallButtonEnabled(): bool
	{
		return true;
	}
}
