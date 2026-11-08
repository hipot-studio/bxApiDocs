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

	private static ?bool $cisRegion = null;

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
			'finishForAllEnabled' => static::isFinishForAllEnabled(),
			'autoBalanceEnabled' => static::isAutoBalanceEnabled(),
			'nqeDowngradeWindowSec' => static::getNqeDowngradeWindowSec(),
			'nqeUpgradeWindowSec' => static::getNqeUpgradeWindowSec(),
			'nqeWarmupSec' => static::getNqeWarmupSec(),
			'nqeHighDowngradePacketsLostPct' => static::getNqeHighDowngradePacketsLostPct(),
			'nqeHighDowngradeRttMs' => static::getNqeHighDowngradeRttMs(),
			'nqeHighDowngradeJitterMs' => static::getNqeHighDowngradeJitterMs(),
			'nqeMedDowngradePacketsLostPct' => static::getNqeMedDowngradePacketsLostPct(),
			'nqeMedDowngradeRttMs' => static::getNqeMedDowngradeRttMs(),
			'nqeMedDowngradeJitterMs' => static::getNqeMedDowngradeJitterMs(),
			'nqeMedUpgradePacketsLostPct' => static::getNqeMedUpgradePacketsLostPct(),
			'nqeMedUpgradeRttMs' => static::getNqeMedUpgradeRttMs(),
			'nqeMedUpgradeJitterMs' => static::getNqeMedUpgradeJitterMs(),
			'nqeLowUpgradePacketsLostPct' => static::getNqeLowUpgradePacketsLostPct(),
			'nqeLowUpgradeRttMs' => static::getNqeLowUpgradeRttMs(),
			'nqeLowUpgradeJitterMs' => static::getNqeLowUpgradeJitterMs(),
			'isLargeMobileCallEnabled' => static::isLargeMobileCallEnabled(),
			'viewToggleEnabled' => static::isViewToggleEnabled(),
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
			'isMetricsEnabled' => static::isMetricsEnabled(),
			'isMetricsLogsEnabled' => static::isMetricsLogsEnabled(),
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
		// A class property rather than a method static: the license region never changes within a
		// process, but tests substitute it, and only a class property can be reset for that.
		if (self::$cisRegion === null)
		{
			self::$cisRegion = \Bitrix\Main\Application::getInstance()->getLicense()->isCis();
		}

		return self::$cisRegion;
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
	 * TTL (in seconds) for the silent card-only conference notification delivered
	 * on start / add-during-call reconcile. Larger than the default pull expiry
	 * (5 s) so a slow client still surfaces the recent card, but bounded so a
	 * stale card cannot linger.
	 */
	private const CONFERENCE_INVITE_TTL = 60;

	/**
	 * Returns the conference-invite pull TTL (seconds). Single source of the value
	 * shared by the signaling, event-handler and conference-invite paths.
	 *
	 * @return int
	 */
	public static function getConferenceInviteTtl(): int
	{
		return self::CONFERENCE_INVITE_TTL;
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

	/**
	 * Whether the mobile "finish call for all" feature is enabled (portal-wide or per-user).
	 *
	 * @return bool
	 */
	public static function isFinishForAllEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if ((bool)Option::get('call', 'finish_for_all_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'finish_for_all_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Whether manual view toggling is available in the mobile call interface.
	 *
	 * Portal-level kill-switch / staged rollout only: no per-user fallback.
	 * Boolean option, off by default: enable with
	 * Option::set('call', 'view_toggle_enabled', true); disable by setting it
	 * falsy or deleting it.
	 *
	 * @return bool
	 */
	public static function isViewToggleEnabled(): bool
	{
		return (bool)Option::get('call', 'view_toggle_enabled', false);
	}

	/**
	 * Whether a guest (a user without a portal account) may start a call.
	 *
	 * Portal-level switch, off by default: enable with
	 * Option::set('call', 'guest_outgoing_call_enabled', 'Y'); disable with 'N' or by deleting
	 * the option. Guests answer calls regardless of this option - it only governs starting one.
	 *
	 * The value is read for what it says rather than cast to bool: 'N' is the usual way of writing
	 * "off" for a portal switch, and (bool)'N' is true.
	 *
	 * @return bool
	 */
	public static function isGuestOutgoingCallEnabled(): bool
	{
		$value = Option::get('call', 'guest_outgoing_call_enabled', 'N');

		return in_array($value, ['Y', '1', 'true'], true);
	}

	/**
	 * Whether a guest may start a call at a bare user id - a 1-1 dialogue rather than a chat.
	 *
	 * The two shapes of a start reach the portal by different schemes: "chatNNN" goes to the conference
	 * provider, a bare user id always goes to Plain ({@see \Bitrix\Call\Controller\Call::startCallAction}
	 * for the JWT end of it). Only under the JWT scheme does that start arrive at the webhook, which lets a
	 * guest through; the classic scheme sends it to CallManager::create, where a bare user id is refused for
	 * a guest whatever the switch above says - resolving it would create a private chat with an employee.
	 *
	 * So the answer for a 1-1 target is the answer above narrowed by the scheme, and it is computed here
	 * rather than reassembled on every client: three screens ask this question and would each have to know
	 * which scheme serves which shape.
	 *
	 * @return bool
	 */
	public static function isGuestOutgoingPrivateCallEnabled(): bool
	{
		return self::isGuestOutgoingCallEnabled() && self::isPlainCallsUseNewScheme();
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
	 * Whether push-to-talk is enabled for the web client.
	 *
	 * @return bool
	 */
	public static function isWebPushToTalkEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'push_to_talk_web_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'push_to_talk_web_enabled', false);
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
	 * Whether auto balance quality is enabled.
	 *
	 * @return bool
	 */
	public static function isAutoBalanceEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_auto_balance_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'call_auto_balance_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * Get downgrade window in seconds.
	 *
	 * @return int
	 */
	public static function getNqeDowngradeWindowSec(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_downgrade_window_sec', 5);
		}

		return $cached;
	}

	/**
	 * Get upgrade window in seconds.
	 *
	 * @return int
	 */
	public static function getNqeUpgradeWindowSec(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_upgrade_window_sec', 15);
		}

		return $cached;
	}

	/**
	 * Get warmup period in seconds.
	 *
	 * @return int
	 */
	public static function getNqeWarmupSec(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_warmup_sec', 30);
		}

		return $cached;
	}

	//region Quality-specific thresholds (HIGH quality)

	/**
	 * Get HIGH→MEDIUM packets lost threshold (%).
	 *
	 * @return int
	 */
	public static function getNqeHighDowngradePacketsLostPct(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_high_downgrade_packets_lost_pct', 5);
		}

		return $cached;
	}

	/**
	 * Get HIGH→MEDIUM RTT threshold (ms).
	 *
	 * Delivered for forward-compatibility only. RTT is not part of the v1 composite
	 * score (reserved for a future version); the value is unused by the client in v1.
	 *
	 * @return int
	 */
	public static function getNqeHighDowngradeRttMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_high_downgrade_rtt_ms', 100);
		}

		return $cached;
	}

	/**
	 * Get HIGH→MEDIUM jitter threshold (ms).
	 *
	 * @return int
	 */
	public static function getNqeHighDowngradeJitterMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_high_downgrade_jitter_ms', 30);
		}

		return $cached;
	}

	//endregion

	//region Quality-specific thresholds (MEDIUM quality)

	/**
	 * Get MEDIUM→LOW packets lost threshold (%).
	 *
	 * @return int
	 */
	public static function getNqeMedDowngradePacketsLostPct(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_med_downgrade_packets_lost_pct', 10);
		}

		return $cached;
	}

	/**
	 * Get MEDIUM→LOW RTT threshold (ms).
	 *
	 * Delivered for forward-compatibility only. RTT is not part of the v1 composite
	 * score (reserved for a future version); the value is unused by the client in v1.
	 *
	 * @return int
	 */
	public static function getNqeMedDowngradeRttMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_med_downgrade_rtt_ms', 200);
		}

		return $cached;
	}

	/**
	 * Get MEDIUM→LOW jitter threshold (ms).
	 *
	 * @return int
	 */
	public static function getNqeMedDowngradeJitterMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_med_downgrade_jitter_ms', 50);
		}

		return $cached;
	}

	/**
	 * Get MEDIUM→HIGH packets lost threshold (%).
	 *
	 * @return int
	 */
	public static function getNqeMedUpgradePacketsLostPct(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_med_upgrade_packets_lost_pct', 2);
		}

		return $cached;
	}

	/**
	 * Get MEDIUM→HIGH RTT threshold (ms).
	 *
	 * Delivered for forward-compatibility only. RTT is not part of the v1 composite
	 * score (reserved for a future version); the value is unused by the client in v1.
	 *
	 * @return int
	 */
	public static function getNqeMedUpgradeRttMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_med_upgrade_rtt_ms', 80);
		}

		return $cached;
	}

	/**
	 * Get MEDIUM→HIGH jitter threshold (ms).
	 *
	 * @return int
	 */
	public static function getNqeMedUpgradeJitterMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_med_upgrade_jitter_ms', 25);
		}

		return $cached;
	}

	//endregion

	//region Quality-specific thresholds (LOW quality)

	/**
	 * Get LOW→MEDIUM packets lost threshold (%).
	 *
	 * @return int
	 */
	public static function getNqeLowUpgradePacketsLostPct(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_low_upgrade_packets_lost_pct', 3);
		}

		return $cached;
	}

	/**
	 * Get LOW→MEDIUM RTT threshold (ms).
	 *
	 * Delivered for forward-compatibility only. RTT is not part of the v1 composite
	 * score (reserved for a future version); the value is unused by the client in v1.
	 *
	 * @return int
	 */
	public static function getNqeLowUpgradeRttMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_low_upgrade_rtt_ms', 150);
		}

		return $cached;
	}

	/**
	 * Get LOW→MEDIUM jitter threshold (ms).
	 *
	 * @return int
	 */
	public static function getNqeLowUpgradeJitterMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'nqe_low_upgrade_jitter_ms', 40);
		}

		return $cached;
	}

	//endregion

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
	 * Whether the new call core (Vue) must be loaded instead of the legacy core.
	 *
	 * Development switch, off by default. Both storages expect the literal 'Y':
	 *   \Bitrix\Main\Config\Option::set('call', 'call_new_core_enabled', 'Y'); // portal-wide
	 *   \CUserOptions::SetOption('call', 'call_new_core_enabled', 'Y');       // current user only
	 * A truthy value of another shape (true, 1) does not enable it.
	 *
	 * @return bool
	 */
	public static function isNewCoreEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (Option::get('call', 'call_new_core_enabled', 'N') === 'Y')
			{
				$cached = true;
			}
			else
			{
				$cached = (\CUserOptions::GetOption('call', 'call_new_core_enabled', 'N') === 'Y');
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
	 * Starting a call through the web SDK of syncconnector is available to the current user.
	 *
	 * Portal-wide or per-user, off by default: enable with
	 * Option::set('call', 'sync_call_enabled', true) or
	 * \CUserOptions::SetOption('call', 'sync_call_enabled', true, false, $userId).
	 * Refused outright without syncconnector: the menu item starts a call through its web SDK.
	 *
	 * @return bool
	 */
	public static function isSyncCallEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (!Loader::includeModule('syncconnector'))
			{
				$cached = false;
			}
			elseif ((bool)Option::get('call', 'sync_call_enabled', false))
			{
				$cached = true;
			}
			else
			{
				$cached = (bool)\CUserOptions::GetOption('call', 'sync_call_enabled', false);
			}
		}

		return $cached;
	}

	/**
	 * The list of active calls of Sync under the native ones in the messenger is available to the current user.
	 *
	 * Portal-wide or per-user, off by default: enable with
	 * Option::set('call', 'sync_active_calls_enabled', true) or
	 * \CUserOptions::SetOption('call', 'sync_active_calls_enabled', true, false, $userId).
	 * Refused outright without syncconnector: the web SDK of syncconnector draws the list.
	 * Independent of sync_call_enabled, so the list and the start button can be turned on separately.
	 *
	 * @return bool
	 */
	public static function isSyncActiveCallsEnabled(): bool
	{
		static $cached = null;
		$cached ??= self::readSyncActiveCallsEnabled();

		return $cached;
	}

	/**
	 * The uncached reading behind isSyncActiveCallsEnabled(): the process caches the answer, and the suite of the
	 * module cannot isolate a process, so the reading is pinned on its own.
	 */
	private static function readSyncActiveCallsEnabled(): bool
	{
		if (!Loader::includeModule('syncconnector'))
		{
			return false;
		}

		if ((bool)Option::get('call', 'sync_active_calls_enabled', false))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'sync_active_calls_enabled', false);
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
