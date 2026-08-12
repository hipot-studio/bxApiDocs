<?php

namespace Bitrix\Call;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIBaasService;

class Settings
{
	public const CALL_CLOUD_RECORDING_ENABLE = 'call_cloud_recording';

	/**
	 * Aggregates the full set of call settings exposed to the mobile client.
	 *
	 * @return array
	 */
	public static function getMobileOptions(): array
	{
		return array_merge([
			'useCustomTurnServer' => self::isSelfTurnServerEnabled(),
			'turnServer' => self::getTurnServer(),
			'turnServerLogin' => Option::get('call', 'turn_server_login', ''),
			'turnServerPassword' => Option::get('call', 'turn_server_password', ''),
			'callLogService' => self::getLogService(),
			'sfuServerEnabled' => self::isCallServerEnabled(),
			'bitrixCallsEnabled' => self::isCallServerEnabled(),
			'callBetaIosEnabled' => self::isIosBetaEnabled(),
			'isAIServiceEnabled' => static::isAIServiceEnabled(),//todo: Deprecated, should be removed after mobile app update
			'userJwt' => JwtCall::getUserJwt((int)CurrentUser::get()->getId()),
			'callBalancerUrl' => static::getBalancerUrl(),
			'jwtCallsEnabled' => static::isNewCallsEnabled(),
			'jwtInPlainCallsEnabled' => static::isPlainCallsUseNewScheme(),
			'plainCallFollowUpEnabled' => static::isPlainCallFollowUpEnabled(),
			'plainCallCloudRecordingEnabled' => static::isPlainCallCloudRecordingEnabled(),
			'optionsForTestingEnabled' => static::isOptionsForTestingEnabled(),
			'mobileCallUIVisibilityTimer' => static::getMobileCallInterfaceVisibilityTimer(),
			'callInvitePeriod' => static::getCallInvitePeriod(),
			'gridViewEnabled' => static::isGridViewEnabled(),
			'isLargeMobileCallEnabled' => static::isLargeMobileCallEnabled(),
			'getBigRoomChats' => static::getBigRoomChats(),
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
			'syncCallInvitePeriod' => static::getSyncCallInvitePeriod(),
		], self::getAdditionalMobileOptions());
	}

	/**
	 * Whether cloud recording is available on the current portal.
	 *
	 * @return bool
	 */
	public static function isCloudRecordingAvailable(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (!\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
			{
				// box
				$cached = static::isCloudRecordTariffEnabled();
			}
			elseif (Loader::includeModule('bitrix24'))
			{
				// b24
				$cached = Feature::isFeatureEnabled(self::CALL_CLOUD_RECORDING_ENABLE);
			}
			else
			{
				$cached = false;
			}
		}

		return $cached;
	}

	/**
	 * Whether the current portal is hosted in the CIS region according to the license.
	 *
	 * @return bool
	 */
	public static function isCisRegion(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = \Bitrix\Main\Application::getInstance()->getLicense()->isCis();
		}

		return $cached;
	}

	/**
	 * Returns extra current-user data appended to the mobile options payload.
	 *
	 * @todo should be moved to callmobile along with the rest of the parameters
	 *
	 * @return array
	 */
	protected static function getAdditionalMobileOptions(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$usersData = Util::getUsers([$userId]);

		return [
			'currentUserData' => $usersData[$userId],
		];
	}

	/**
	 * Whether the portal is configured to use its own TURN server instead of the default Bitrix one.
	 *
	 * @return bool
	 */
	public static function isSelfTurnServerEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = Option::get('call', 'turn_server_self') === 'Y';
		}

		return $cached;
	}

	/**
	 * Whether the dedicated conference chat is enabled on the portal.
	 *
	 * @return bool
	 */
	public static function isConferenceChatEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (bool)Option::get('call', 'conference_chat_enabled', true);
		}

		return $cached;
	}

	/**
	 * Whether the Call AI feature is enabled on the portal.
	 *
	 * @return bool
	 */
	public static function isAIServiceEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: '';
			if ($region === 'cn')
			{
				$cached = false;
			}
			else
			{
				$cached = (bool)Option::get('call', 'call_ai_enabled');
			}
		}

		return $cached;
	}

	/**
	 * Indicating whether the TCP-SDP transport must be used for WebRTC.
	 *
	 * @return bool
	 */
	public static function useTcpSdp(): bool
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

	//region JWT

	/**
	 * Returns the URL of the call balancer service that distributes calls between media servers.
	 *
	 * @return string
	 */
	public static function getBalancerUrl(): string
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (new BalancerClient())->getServiceUrl();
		}

		return $cached;
	}

	/**
	 * Registers the portal in the JWT call infrastructure.
	 *
	 * @deprecated
	 * @return bool
	 */
	public static function registerPortalKey(): bool
	{
		return JwtCall::registerPortal()->isSuccess();
	}

	/**
	 * Agent entry point for retrying portal registration in the JWT call infrastructure.
	 *
	 * @deprecated
	 * @param int $retryCount
	 * @return string
	 */
	public static function registerPortalKeyAgent(int $retryCount = 1): string
	{
		return JwtCall::registerPortalAgent();
	}

	/**
	 * Whether the new (V2/JWT) call scheme is enabled on the portal and the portal is registered.
	 *
	 * @return bool
	 */
	public static function isNewCallsEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$defaultValue = \Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24');

			$cached =
				(bool)Option::get('call', 'call_v2_enabled', $defaultValue)
				&& (self::getPortalId() > 0)
			;
		}

		return $cached;
	}

	/**
	 * Returns the portal's private RSA key used to sign JWT tokens.
	 * Decrypts the stored value with the configured crypto key when present.
	 *
	 * @return string
	 */
	public static function getPrivateKey(): string
	{
		static $cached = null;
		if ($cached === null)
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

			$cached = $privateKey;
		}

		return $cached;
	}

	/**
	 * Returns the portal identifier issued by the call infrastructure on registration.
	 *
	 * @return int
	 */
	public static function getPortalId(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_portal_id', 0);
		}

		return $cached;
	}

	/**
	 * Whether 1-1 (P2P) calls must use the new JWT-based scheme.
	 * The module-level option takes priority over the per-user setting.
	 *
	 * @return bool
	 */
	public static function isPlainCallsUseNewScheme(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'plain_calls_use_new_scheme', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'plain_calls_use_new_scheme', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether AI follow-up generation is enabled for 1-1 (P2P) calls.
	 *
	 * @return bool
	 */
	public static function isPlainCallFollowUpEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'plain_call_follow_up_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'plain_call_follow_up_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether cloud recording is enabled for 1-1 (P2P) calls.
	 *
	 * @return bool
	 */
	public static function isPlainCallCloudRecordingEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'plain_call_cloud_recording_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'plain_call_cloud_recording_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether the testing-only options panel is exposed in the client.
	 *
	 * @return bool
	 */
	public static function isOptionsForTestingEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'options_for_testing_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'options_for_testing_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether grid view is enabled in the call interface.
	 *
	 * @return bool
	 */
	public static function isGridViewEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'grid_view_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'grid_view_enabled', false);
			}
		}

		return $cached;
	}

	public static function isLargeMobileCallEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'large_mobile_call_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'large_mobile_call_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Returns the timeout (ms) for hiding the mobile call UI while the call is active.
	 *
	 * @return int
	 */
	public static function getMobileCallInterfaceVisibilityTimer(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'mobile_call_ui_visibility_timer', 0);
		}

		return $cached;
	}

	/**
	 * Returns the ring/invite period for an outgoing call, in milliseconds.
	 * Defines how long the recipient is rung before the client-side timeout fires (`onInviteTimeout` on the frontend).
	 * Configurable via `call.call_invite_period` option. Default: 30000 ms.
	 *
	 * @return int Ring/invite period in milliseconds.
	 */
	public static function getCallInvitePeriod(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_invite_period', 30000);
		}

		return $cached;
	}

	/**
	 * Updates portal-level flags describing the availability of the V2 (JWT) scheme.
	 *
	 * @deprecated
	 *
	 * @param bool $isJwtEnabled
	 * @param bool $isPlainUseJwt
	 * @param string $callBalancerUrl
	 * @param string $callServerUrl
	 * @return void
	 */
	public static function updateCallV2Availability(bool $isJwtEnabled, bool $isPlainUseJwt, string $callBalancerUrl = '', string $callServerUrl = ''): void
	{
		JwtCall::updateCallV2Availability($isJwtEnabled, $isPlainUseJwt, $callBalancerUrl, $callServerUrl);
	}

	/**
	 * Whether the "disable camera of new joined users" feature is enabled.
	 * The module-level option takes priority over the per-user setting.
	 *
	 * @return bool
	 */
	public static function isDisableCameraNewJoinedUsersFeatureEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_disable_camera_new_joined_users_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_disable_camera_new_joined_users_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether logs to Kibana are enabled.
	 *
	 * @return bool
	 */
	public static function isKibanaLogsEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_kibana_logs_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_kibana_logs_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether logs to console are enabled.
	 *
	 * @return bool
	 */
	public static function isConsoleLogsEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_console_logs_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_console_logs_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Returns the participant count threshold used by the "disable camera of new joined users" feature.
	 *
	 * @return int
	 */
	public static function countDisableCameraNewJoinedUsersFeature(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_disable_camera_new_joined_users_count', 4);
		}

		return $cached;
	}

	/**
	 * Whether stream quality control is enabled.
	 *
	 * @return bool
	 */
	public static function isStreamQualityFeatureEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_stream_quality_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_stream_quality_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether call metrics collection is enabled.
	 *
	 * @return bool
	 */
	public static function isMetricsEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_metrics_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_metrics_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether call metrics logs are enabled.
	 *
	 * @return bool
	 */
	public static function isMetricsLogsEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_metrics_logs_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_metrics_logs_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether cloud recording is enabled.
	 *
	 * @return bool
	 */
	public static function isCloudRecordEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_cloud_record', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_cloud_record', false);
			}
		}

		return $cached;
	}


	/**
	 * Whether the cloud call record tariff flag is enabled (test/legacy switch).
	 *
	 * @TODO: Delete after adding tariffs
	 *
	 * @return bool
	 */
	public static function isCloudRecordTariffEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_cloud_record_tariff', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_cloud_record_tariff', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether the cloud call record verbose log is enabled.
	 *
	 * @TODO: Delete after testing
	 *
	 * @return bool
	 */
	public static function isCloudRecordLogEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_cloud_record_log', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_cloud_record_log', false);
			}
		}

		return $cached;
	}

	/**
	 * Returns the send interval of accident logs in seconds.
	 * The module-level option takes priority over the per-user setting.
	 *
	 * @return int
	 */
	public static function getAccidentLogSendIntervalSecs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$commonAccidentLogSendIntervalSecs = (int)Option::get('call', 'accident_log_send_interval_secs', 0);

			if ($commonAccidentLogSendIntervalSecs > 0)
			{
				$cached = $commonAccidentLogSendIntervalSecs;
			}
			else
			{
				$cached = (int)\CUserOptions::GetOption('call', 'accident_log_send_interval_secs', 0);
			}
		}

		return $cached;
	}

	/**
	 * Returns the maximum age of an accident log group, in seconds.
	 * The module-level option takes priority over the per-user setting.
	 *
	 * @return int
	 */
	public static function getAccidentLogGroupMaxAgeSecs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$commonAccidentLogGroupMaxAgeSecs = (int)Option::get('call', 'accident_log_group_max_age_secs', 0);

			if ($commonAccidentLogGroupMaxAgeSecs > 0)
			{
				$cached = $commonAccidentLogGroupMaxAgeSecs;
			}
			else
			{
				$cached = (int)\CUserOptions::GetOption('call', 'accident_log_group_max_age_secs', 0);
			}
		}

		return $cached;
	}

	/**
	 * Returns the list of chat IDs treated as big-room chats.
	 *
	 * @todo Remove in future
	 *
	 * @return int[]
	 */
	public static function getBigRoomChats(): array
	{
		static $chatIds;
		if ($chatIds === null)
		{
			$chatIds = [];
			$option = Option::get('call', 'big_room_chats', '');
			if (!empty($option))
			{
				$chatIds = array_filter(array_map('intVal', explode(',', $option)));
			}
		}

		return $chatIds;
	}

	/**
	 * Whether the Vue-based call interface is enabled.
	 *
	 * @return bool
	 */
	public static function isVueEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_vue_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_vue_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether collecting call feedback is allowed for the current portal.
	 *
	 * @return bool
	 */
	public static function isFeedbackAllowed(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Loader::includeModule('bitrix24'))
			{
				$cached = \CBitrix24::getPortalZone() == 'ru';
			}
			else
			{
				$cached = Option::get('call', 'allow_call_feedback', 'N') === 'Y';
			}
		}

		return $cached;
	}

	/**
	 * Returns the maximum number of call extensions allowed by the current Bitrix24 tariff.
	 *
	 * @return int
	 */
	public static function getMaxCallLimit(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			if (!\Bitrix\Main\Loader::includeModule('bitrix24'))
			{
				$cached = 0;
			}
			else
			{
				$cached = (int)\Bitrix\Bitrix24\Feature::getVariable('im_call_extensions_limit');
			}
		}

		return $cached;
	}

	/**
	 * Returns the maximum number of participants supported by the call server.
	 *
	 * @return int
	 */
	public static function getMaxCallServerParticipants(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Loader::includeModule('bitrix24'))
			{
				$cached = (int)\Bitrix\Bitrix24\Feature::getVariable('im_max_call_participants');
			}
			else
			{
				$cached = (int)Option::get('call', 'call_server_max_users');
			}
		}

		return $cached;
	}

	/**
	 * Returns the effective participant limit for the active call mode (server or P2P/TURN).
	 *
	 * @return int
	 */
	public static function getMaxParticipants(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			if (self::isCallServerEnabled())
			{
				$cached = self::getMaxCallServerParticipants();
			}
			else
			{
				$cached = (int)Option::get('call', 'turn_server_max_users');
			}
		}

		return $cached;
	}

	/**
	 * Whether the SFU/MCU call server is enabled and available.
	 *
	 * @return bool
	 */
	public static function isCallServerEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (!Loader::includeModule('call'))
			{
				$cached = false;
			}
			else
			{
				$cached = (bool)Option::get('call', 'call_server_enabled');
			}
		}

		return $cached;
	}

	/**
	 * Returns the identifier of the external log service used by the call client.
	 *
	 * @return string
	 */
	public static function getLogService(): string
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (string)Option::get('call', 'call_log_service', '');
		}

		return $cached;
	}

	/**
	 * Whether the iOS beta build of the call client is enabled.
	 *
	 * @return bool
	 */
	public static function isIosBetaEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = Option::get('call', 'call_beta_ios', 'N') === 'Y';
		}

		return $cached;
	}

	/**
	 * Returns the TURN server hostname used by the call client.
	 * If a self-hosted TURN server is configured, returns its address; otherwise picks
	 * the regional default (CIS or global).
	 *
	 * @return string
	 */
	public static function getTurnServer(): string
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Settings::isSelfTurnServerEnabled())
			{
				$cached = (string)Option::get('call', 'turn_server');
			}
			elseif (\Bitrix\Main\Application::getInstance()->getLicense()->isCis())
			{
				$cached = 'turn.bitrix24.tech';
			}
			else
			{
				$cached = 'turn.calls.bitrix24.com';
			}
		}

		return $cached;
	}

	public static function getSyncCallInvitePeriod(): int
	{
		return (int)Option::get('call', 'call_sync_invite_period', 300000);
	}

	/**
	 * Sync preset feature is enabled for the portal.
	 * @return bool
	 */
	public static function isSyncPresetEnabled(): bool
	{
		if (Option::get('call', 'call_sync_preset_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_sync_preset_enabled', false);
	}

	/**
	 * Returns current left menu preset code.
	 * @return string
	 */
	public static function getActivePresetCode(): string
	{
		if (!\Bitrix\Main\Loader::includeModule('intranet'))
		{
			return '';
		}

		return \Bitrix\Intranet\UI\LeftMenu\Preset\Manager::getPreset()->getCode();
	}

}
