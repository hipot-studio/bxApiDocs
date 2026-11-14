<?php

namespace Bitrix\Call;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Feature as FeatureFlag;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIBaasService;
use Bitrix\Call\Promotion\ReactionsOnboardingPromo;
use Bitrix\Call\Public\Features;
use Bitrix\Call\Reaction\ReactionCatalog;

class Settings
{
	public const CALL_CLOUD_RECORDING_ENABLE = 'call_cloud_recording';
	private const BASE_TURN_SERVER_CREDENTIAL = 'bitrix';

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
			'turnServerLogin' => self::getTurnServerLogin(),
			'turnServerPassword' => self::getTurnServerPassword(),
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
			'reactions' => array_merge(self::getReactionSettings(), [
				'maxOnScreen' => self::getMobileReactionMaxOnScreen(),
			]),
			'autoBalanceEnabled' => static::isAutoBalanceEnabled(),
			'isAudioLossResilienceEnabled' => static::isAudioLossResilienceEnabled(),
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
			'callQosEnabled' => static::isQoSEnabledForMobile(),
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
	 * Whether the portal uses its own TURN server instead of the default Bitrix one.
	 * A saved configuration that fails validation is treated as disabled.
	 *
	 * @return bool
	 */
	public static function isSelfTurnServerEnabled(): bool
	{
		return
			Option::get('call', 'turn_server_self') === 'Y'
			&& self::isTurnServerConfigValid(
				trim((string)Option::get('call', 'turn_server')),
				(string)Option::get('call', 'turn_server_login'),
				(string)Option::get('call', 'turn_server_password'),
			)
		;
	}

	/**
	 * Checks a custom TURN server address: a host name, IPv4 or bracketed IPv6 address with an optional port.
	 * Clients add the stun: and turn: schemes themselves, so the address must not contain them.
	 *
	 * @param string $address
	 * @return bool
	 */
	public static function isTurnServerAddressValid(string $address): bool
	{
		$label = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
		$pattern = '/^(?:\[([0-9a-f:.]+)\]|' . $label . '(?:\.' . $label . ')*)(?::(\d{1,5}))?$/iD';
		if (!preg_match($pattern, $address, $matches))
		{
			return false;
		}

		$ipv6 = $matches[1] ?? '';
		if ($ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
		{
			return false;
		}

		$port = $matches[2] ?? '';

		return $port === '' || ((int)$port >= 1 && (int)$port <= 65535);
	}

	/**
	 * Checks a custom TURN server configuration: a valid address and non-empty credentials.
	 *
	 * @param string $address
	 * @param string $login
	 * @param string $password
	 * @return bool
	 */
	public static function isTurnServerConfigValid(string $address, string $login, string $password): bool
	{
		return
			self::isTurnServerAddressValid($address)
			&& trim($login) !== ''
			&& trim($password) !== ''
		;
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
			if (FeatureFlag::isEnabled(Features\PlainCallFollowUpFlag::class))
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
			if (FeatureFlag::isEnabled(Features\PlainCallCloudRecordingFlag::class))
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
			if (FeatureFlag::isEnabled(Features\GridViewFlag::class))
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
			if (FeatureFlag::isEnabled(Features\FinishForAllFlag::class))
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
	 * Whether call reactions are enabled (portal-wide or per-user).
	 *
	 * Gates showing the picker and sending a reaction; receiving is never gated by this flag.
	 *
	 * @return bool
	 */
	public static function isCallReactionsEnabled(): bool
	{
		if (FeatureFlag::isEnabled(Features\ReactionsFlag::class))
		{
			return true;
		}

		// Personal values are stored as strings too; (bool)'N' would be true.
		$truthy = ['Y', '1', 1, true];

		return in_array(\CUserOptions::GetOption('call', 'call_reactions_enabled', false), $truthy, true);
	}

	/**
	 * Returns the minimal interval (ms) between two reactions sent by the same user.
	 *
	 * @return int
	 */
	public static function getReactionThrottleMs(): int
	{
		return (int)Option::get('call', 'call_reactions_send_throttle_ms', 600);
	}

	/**
	 * Returns how long (ms) a reaction stays on the screen while floating up.
	 *
	 * @return int
	 */
	public static function getReactionFloatDurationMs(): int
	{
		return (int)Option::get('call', 'call_reactions_float_duration_ms', 4000);
	}

	/**
	 * Returns the maximum number of reactions animated on the screen at the same time.
	 *
	 * @return int
	 */
	public static function getReactionMaxOnScreen(): int
	{
		return (int)Option::get('call', 'call_reactions_max_on_screen', 20);
	}

	/**
	 * Returns the on-screen reaction limit used by the mobile client.
	 *
	 * Mobile keeps a separate, lower limit on purpose: it renders only the few newest pills, the older ones are
	 * already fully transparent, so the shared limit would hold invisible slots and delay the queued reactions.
	 *
	 * @return int
	 */
	public static function getMobileReactionMaxOnScreen(): int
	{
		return (int)Option::get('call', 'call_reactions_mobile_max_on_screen', 6);
	}

	/**
	 * Returns the size of the pending reaction queue; everything above it is dropped.
	 *
	 * @return int
	 */
	public static function getReactionMaxQueue(): int
	{
		return (int)Option::get('call', 'call_reactions_max_queue', 100);
	}

	/**
	 * Reaction settings shared by the web bootstrap and the mobile options.
	 *
	 * @return array
	 */
	public static function getReactionSettings(): array
	{
		return [
			'enabled' => self::isCallReactionsEnabled(),
			'sendThrottleMs' => self::getReactionThrottleMs(),
			'floatDurationMs' => self::getReactionFloatDurationMs(),
			'maxOnScreen' => self::getReactionMaxOnScreen(),
			'maxQueue' => self::getReactionMaxQueue(),
			'seed' => ReactionCatalog::SEED,
			// Only tells the client it may show the hint: the show is taken by
			// call.Reaction.claimOnboarding, so delivering the flag does not spend the onboarding.
			'onboardingAvailable' => ReactionsOnboardingPromo::needToShow(),
		];
	}

	/**
	 * Whether manual view toggling is available in the mobile call interface.
	 *
	 * Portal-level kill-switch / staged rollout only: no per-user fallback.
	 * Off by default: toggled by the ViewToggleFlag feature flag.
	 *
	 * @return bool
	 */
	public static function isViewToggleEnabled(): bool
	{
		return FeatureFlag::isEnabled(Features\ViewToggleFlag::class);
	}

	/**
	 * Whether a guest (a user without a portal account) may start a call. Always: a guest starts a call in
	 * a chat of their own like anyone else, and the chats a guest may ring are held by GuestCallPolicy.
	 *
	 * Kept as a method rather than inlined: im and the mobile clients read the answer as a feature flag of
	 * the portal, and im of earlier versions calls it directly. Turning guests off altogether is the job of
	 * the im setting of chats with guests.
	 *
	 * @deprecated The answer is constant. Still called by im (Features::isGuestOutgoingCallAvailable) and sent to
	 * the mobile clients as guestOutgoingCallEnabled (OnBuildEnvVariable, read by the callmobile call list);
	 * remove once the installed versions of im and the mobile modules no longer read it.
	 *
	 * @return bool
	 */
	public static function isGuestOutgoingCallEnabled(): bool
	{
		return true;
	}

	/**
	 * Whether a guest may start a call at a bare user id - a 1-1 dialogue rather than a chat.
	 *
	 * The two shapes of a start reach the portal by different schemes: "chatNNN" goes to the conference
	 * provider, a bare user id always goes to Plain ({@see \Bitrix\Call\Controller\Call::startCallAction}
	 * for the JWT end of it). Only under the JWT scheme does that start arrive at the webhook, which lets a
	 * guest through; the classic scheme sends it to CallManager::create, where a bare user id is refused for
	 * a guest - resolving it would create a private chat with an employee.
	 *
	 * So the answer for a 1-1 target is the scheme itself, and it is computed here
	 * rather than reassembled on every client: three screens ask this question and would each have to know
	 * which scheme serves which shape.
	 *
	 * @return bool
	 */
	public static function isGuestOutgoingPrivateCallEnabled(): bool
	{
		return self::isPlainCallsUseNewScheme();
	}

	public static function isLargeMobileCallEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (FeatureFlag::isEnabled(Features\LargeMobileCallFlag::class))
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
			if (FeatureFlag::isEnabled(Features\DisableCameraForNewJoinersFlag::class))
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
			if (FeatureFlag::isEnabled(Features\WebPushToTalkFlag::class))
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
			if (FeatureFlag::isEnabled(Features\StreamQualityFlag::class))
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
	 * Whether the QoS (Quality of Service) indicator feature is enabled on the portal.
	 * Portal level is the QosFlag feature flag, with the personal `call_qos_enabled` option on top.
	 * This is the global master switch; the mobile client is gated additionally by
	 * isQoSEnabledForMobile().
	 *
	 * @return bool
	 */
	public static function isQoSEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			if (FeatureFlag::isEnabled(Features\QosFlag::class))
			{
				$cached = true;
			}
			else
			{
				// Personal values are stored as strings too; (bool)'N' would be true.
				$truthy = ['Y', '1', 1, true];
				$cached = in_array(\CUserOptions::GetOption('call', 'call_qos_enabled', 'N'), $truthy, true);
			}
		}

		return $cached;
	}

	/**
	 * Whether audio loss resilience is enabled.
	 *
	 * @return bool
	 */
	public static function isAudioLossResilienceEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			// Enabled by default: the module option must be explicitly set to 'N' to turn the feature off.
			if (Option::get('call', 'call_audio_loss_resilience_enabled', 'Y') === 'Y')
			{
				$cached = true;
			}
			else
			{
				$cached = (\CUserOptions::GetOption('call', 'call_audio_loss_resilience_enabled', 'N') === 'Y');
			}
		}

		return $cached;
	}

	/**
	 * Whether the QoS indicator is enabled for the mobile client.
	 *
	 * Mobile has its own portal-level kill-switch (MobileQosFlag) so QoS can be held back on
	 * mobile independently of web until the native app catches up. Enablement requires BOTH
	 * isQoSEnabled() (with its personal level) AND the mobile flag. The mobile flag is portal level
	 * only (no per-user override); off by default (opt-in) — mobile QoS
	 * stays off even when web QoS is on until it is explicitly enabled.
	 *
	 * Exposed to the mobile client via getMobileOptions() as `callQosEnabled`.
	 *
	 * @return bool
	 */
	public static function isQoSEnabledForMobile(): bool
	{
		if (!static::isQoSEnabled())
		{
			return false;
		}

		static $cached = null;
		if ($cached === null)
		{
			$cached = FeatureFlag::isEnabled(Features\MobileQosFlag::class);
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
			if (FeatureFlag::isEnabled(Features\AutoBalanceFlag::class))
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

	//region 1-1 media server switch (Follow-Up)

	/**
	 * Get the SDP-answer wait timeout (ms) used when publishing/switching to the media server.
	 *
	 * @return int
	 */
	public static function getMediaSwitchAnswerTimeoutMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_media_switch_answer_timeout_ms', 10000);
		}

		return $cached;
	}

	/**
	 * Get the overall deadline (ms) for a successful switch to the media server.
	 *
	 * @return int
	 */
	public static function getMediaSwitchDeadlineMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_media_switch_deadline_ms', 15000);
		}

		return $cached;
	}

	//endregion

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

	//region Web background

	/**
	 * Whether the virtual background is enabled in web calls.
	 *
	 * @return bool
	 */
	public static function isWebBackgroundEnabled(): bool
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = FeatureFlag::isEnabled(Features\WebBackgroundFlag::class);
		}

		return $cached;
	}

	/**
	 * Returns the minimum number of CPU cores required for the web background.
	 *
	 * @return int
	 */
	public static function getWebBackgroundMinCores(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_web_background_min_cores', 4);
		}

		return $cached;
	}

	/**
	 * Returns the minimum device memory in gigabytes required for the web background.
	 *
	 * @return int
	 */
	public static function getWebBackgroundMinMemoryGb(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_web_background_min_memory_gb', 4);
		}

		return $cached;
	}

	/**
	 * Returns the list of WebGL renderer markers the web background is not allowed on.
	 *
	 * @return string[]
	 */
	public static function getWebBackgroundRendererDenylist(): array
	{
		static $renderers = null;
		if ($renderers === null)
		{
			$renderers = [];
			$option = Option::get('call', 'call_web_background_renderer_denylist', 'swiftshader,llvmpipe,basic render');
			if (!empty($option))
			{
				$renderers = array_values(array_filter(array_map('trim', explode(',', $option)), 'strlen'));
			}
		}

		return $renderers;
	}

	/**
	 * Returns the number of frames measured by the client performance probe.
	 *
	 * @return int
	 */
	public static function getWebBackgroundProbeFrames(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_web_background_probe_frames', 25);
		}

		return $cached;
	}

	/**
	 * Returns the per-frame processing time in milliseconds the probe is allowed to reach.
	 *
	 * @return int
	 */
	public static function getWebBackgroundProbeThresholdMs(): int
	{
		static $cached = null;
		if ($cached === null)
		{
			$cached = (int)Option::get('call', 'call_web_background_probe_threshold_ms', 40);
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
			if (FeatureFlag::isEnabled(Features\CloudRecordFlag::class))
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
		if (self::isSelfTurnServerEnabled())
		{
			return trim((string)Option::get('call', 'turn_server'));
		}

		return self::isCisRegion() ? 'turn.bitrix24.tech' : 'turn.calls.bitrix24.com';
	}

	/**
	 * TURN login for clients: the custom one, the base server one when the saved custom server is invalid,
	 * or the default one when the custom server is off.
	 *
	 * @return string
	 */
	public static function getTurnServerLogin(): string
	{
		return self::getTurnServerCredential('turn_server_login');
	}

	/**
	 * TURN password for clients: the custom one, the base server one when the saved custom server is invalid,
	 * or the default one when the custom server is off.
	 *
	 * @return string
	 */
	public static function getTurnServerPassword(): string
	{
		return self::getTurnServerCredential('turn_server_password');
	}

	private static function getTurnServerCredential(string $optionName): string
	{
		if (self::isSelfTurnServerEnabled())
		{
			return (string)Option::get('call', $optionName);
		}

		if (Option::get('call', 'turn_server_self') === 'Y')
		{
			// Clients fall back to the base server here, so they need its credentials rather than the defaults
			// a portal may override in bitrix/php_interface/call_options.php
			return self::BASE_TURN_SERVER_CREDENTIAL;
		}

		return (string)(Option::getDefaults('call')[$optionName] ?? '');
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
		if (FeatureFlag::isEnabled(Features\SyncPresetFlag::class))
		{
			return true;
		}

		return (bool)\CUserOptions::GetOption('call', 'call_sync_preset_enabled', false);
	}

	/**
	 * Sync preset feature is enabled for the whole portal, the personal option is not considered.
	 * A choice applied to every user of the portal must not depend on the personal option of one of them.
	 * @return bool
	 */
	public static function isSyncPresetEnabledForPortal(): bool
	{
		return FeatureFlag::isEnabled(Features\SyncPresetFlag::class);
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
	 * The main call button of the header of a group chat starts a call of Sync through the web SDK of syncconnector,
	 * and its menu offers no call of this module. A private chat keeps the calls of this module.
	 *
	 * Off by default: enable with Feature::enable(SyncPrimaryCallFlag::class), or for chosen users with an allow rule.
	 * Refused outright without syncconnector: the button starts the call through its web SDK.
	 * Independent of sync_call_enabled, whose menu item is left out while this one is on.
	 *
	 * @return bool
	 */
	public static function isSyncPrimaryCallEnabled(): bool
	{
		return Loader::includeModule('syncconnector') && FeatureFlag::isEnabled(Features\SyncPrimaryCallFlag::class);
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
