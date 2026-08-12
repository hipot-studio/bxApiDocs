<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Auth;

use Bitrix\Call\Settings;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Guest\Auth\AuthorizationService;
use Bitrix\Im\V2\Guest\Auth\InviteCode;
use Bitrix\Im\V2\Guest\Auth\JoinStatus;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Intranet\Enum\UserRole;
use Bitrix\Intranet\Service\MobileAppSettings;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Mobile\AvaMenu\Profile\Profile;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Feature\MenuFeature;
use Bitrix\Mobile\Provider\ThemeProvider;
use Bitrix\Mobile\Tab\Manager as TabManager;
use Bitrix\MobileApp\Janative\Manager as JanativeManager;
use Bitrix\Pull\Config;

/**
 * Guest checkout for the mobile.data `checkout` action: warm-start same-chat validation,
 * join-by-code, and the guest success/error response builders.
 *
 * {@see handle()} returns the native payload, or null for a PORTAL_USER who followed a guest link
 * (caller falls through to standard auth). The `im` module must be loaded before instantiation.
 */
final class GuestCheckout
{
	private const SERVICE_MOBILE_APP_SETTINGS = 'intranet.option.mobile_app';
	private const COMPONENT_COMMUNICATION = 'communication';
	private const COMPONENT_BACKGROUND = 'background';
	private const COMPONENT_CALLS = 'calls';
	private const COMPONENT_CALLS_SCRIPT = 'call:calls';

	public function __construct(private readonly \CUser $user)
	{
	}

	public function handle(string $guestCode, ?string $guestName): ?array
	{
		$isSessionGuest = $this->user->IsAuthorized()
			&& $this->user->GetParam('EXTERNAL_AUTH_ID') === UserRole::IM_GUEST->value;

		$guestToken = Token::createFromRequest();

		// A guest already in the requested chat short-circuits joinByCode (whose addUserToChat
		// re-bumps Recent on every checkout). Cross-chat falls through to joinByCode.
		$sessionGuestLink = $isSessionGuest
			? SharingLinkFactory::getInstance()->getLinkByCode($guestCode)
			: null;
		$isSessionGuestMember = $sessionGuestLink instanceof GuestChatLink
			&& Chat::getInstance($sessionGuestLink->getChatId())->getRelationByUserId((int)$this->user->GetID()) !== null;

		return $isSessionGuestMember
			? $this->handleWarmStart($guestCode, $guestToken)
			: $this->handleJoin($guestCode, $guestName, $guestToken);
	}

	/**
	 * Guest already in the requested chat: re-validate and re-authorize, skipping joinByCode so
	 * addUserToChat does not re-bump Recent.
	 */
	private function handleWarmStart(string $guestCode, ?Token $guestToken): array
	{
		// Re-validate: link/inviter rights + token resolves to THIS guest (expectedUserId).
		$validation = $guestToken === null
			? null
			: GuestService::getInstance()->validateGuestSession(
				$guestCode,
				$guestToken,
				(int)$this->user->GetID(),
			);
		if ($validation === null || !$validation->isSuccess())
		{
			$errors = $validation?->getErrors() ?? [];

			// No token, or a current-session failure → drop the account; a bad DIFFERENT link → keep.
			$sessionInvalid = $validation === null
				|| $this->shouldDropGuestSession($guestToken, $errors, $guestCode);

			return $this->failureResponse($sessionInvalid, $errors);
		}

		$userId = (int)$this->user->GetID();
		$this->user->Authorize($userId, false, false, MobileGuestApplication::ID);

		// Refresh the invite-code cookie to the code just navigated to.
		AuthorizationService::getInstance()->setInviteCode($guestCode);

		return $this->buildResponse(
			userId: $userId,
			userName: $this->user->GetFullName(),
			userLogin: $this->user->GetLogin(),
			token: null,
			requestGuestName: false,
			guestCode: $guestCode,
		);
	}

	/**
	 * Cold start or cross-chat: join the guest to the requested chat and authorize, or return null
	 * for a PORTAL_USER who followed a guest link (caller continues to standard auth).
	 */
	private function handleJoin(string $guestCode, ?string $guestName, ?Token $guestToken): ?array
	{
		$guestResult = GuestService::getInstance()->joinByCode($guestCode, $guestToken, $guestName);

		if (!$guestResult->isSuccess())
		{
			// Same rule as warm-start: a current-session failure → drop the account; a bad link to
			// another chat on a live session → keep it.
			$errors = $guestResult->getErrors();

			return $this->failureResponse(
				$this->shouldDropGuestSession($guestToken, $errors, $guestCode),
				$errors,
			);
		}

		if ($guestResult->getJoinStatus() === JoinStatus::PORTAL_USER)
		{
			return null;
		}

		$guestUser = $guestResult->getUser();
		$chat = $guestResult->getChat();
		if ($guestUser === null || $chat === null)
		{
			// Join reported success but user/chat is missing — internal inconsistency, not a dead
			// session → no guestSessionInvalid.
			return $this->buildError([]);
		}

		// Narrow 'im_guest_mobile' scope: adds the native URLs the base 'im_guest' lacks, while
		// staying narrower than the full MobileApplication scope.
		$userId = (int)$guestUser->getId();
		$this->user->Authorize($userId, false, false, MobileGuestApplication::ID);

		$requestGuestName = $guestResult->getJoinStatus() === JoinStatus::NEW_GUEST
			&& ($guestName === null || $guestName === '');

		return $this->buildResponse(
			userId: $userId,
			userName: $guestUser->getName(),
			userLogin: $this->user->GetLogin(),
			token: $guestResult->getToken(),
			requestGuestName: $requestGuestName,
			guestCode: $guestCode,
		);
	}

	/**
	 * Invalid current session → terminate it and have the native drop the account; otherwise a
	 * plain error that keeps the session.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function failureResponse(bool $sessionInvalid, array $errors): array
	{
		if ($sessionInvalid)
		{
			AuthorizationService::getInstance()->terminate();

			return $this->buildError($errors, guestSessionInvalid: true);
		}

		return $this->buildError($errors);
	}

	/**
	 * Whether a checkout failure invalidates the guest's CURRENT session (→ native drops the account
	 * via guestSessionInvalid), vs. merely rejecting the link the guest navigated to (→ keep account).
	 * A logged-in non-guest (portal user) is never dropped — they have no guest session. Otherwise
	 * true for session-level reasons (feature off, or dead/foreign token — any code); for a link/chat
	 * error only when the failing code is the session's own current invite code, or — with no current
	 * code — the token is also gone/dead (orphan cleanup, not a valid guest who just lacks the cookie).
	 *
	 * @param \Bitrix\Main\Error[] $errors the first error carries the reason code
	 */
	private function shouldDropGuestSession(?Token $token, array $errors, string $navigatedCode): bool
	{
		// A logged-in non-guest (e.g. an employee who followed a guest link with a bad code) has no
		// guest session/account to drop.
		if ($this->isAuthorizedNonGuest())
		{
			return false;
		}

		$reason = ($errors[0] ?? null)?->getCode();
		$sessionLevelReason = in_array($reason, [
			AuthError::GUEST_FEATURE_DISABLED,
			AuthError::GUEST_NOT_FOUND,
			AuthError::DIFFERENT_GUEST,
			AuthError::NOT_GUEST,
		], true);
		if ($sessionLevelReason)
		{
			return true;
		}

		$currentCode = InviteCode::createFromRequest()?->getValue();
		if ($currentCode !== null)
		{
			return $navigatedCode === $currentCode;
		}

		// No invite cookie: orphan (→ flag) only if the token is gone/dead; a live token = keep.
		return !GuestService::getInstance()->isGuestTokenValid($token);
	}

	private function isAuthorizedNonGuest(): bool
	{
		return $this->user->IsAuthorized()
			&& $this->user->GetParam('EXTERNAL_AUTH_ID') !== UserRole::IM_GUEST->value;
	}

	private function buildResponse(
		int $userId,
		string $userName,
		string $userLogin,
		?string $token,
		bool $requestGuestName,
		string $guestCode
	): array
	{
		$moduleVersion = $this->getModuleVersion();
		$pullConfig = Loader::includeModule('pull') ? Config::get(['JSON' => true]) : null;

		$context = new Context([
			'siteId' => SITE_ID,
			'siteDir' => SITE_DIR,
			'version' => $moduleVersion,
			'isGuest' => true,
			'requestGuestName' => $requestGuestName,
			'guestCode' => $guestCode,
		]);

		$tabManager = new TabManager($context);
		$profile = new Profile($context);
		$themeProvider = new ThemeProvider($userId, 'bitrix24');

		[$canCopyText, $canTakeScreenshot] = $this->resolveMobilePermissions();

		$response = [
			'status' => 'success',
			'id' => $userId,
			'login' => $userLogin,
			'name' => $userName,
			'sessid_md5' => bitrix_sessid(),
			'cloud' => ModuleManager::isModuleInstalled('bitrix24')
				&& \COption::GetOptionString('bitrix24', 'network', 'N') === 'Y',
			'backend_version' => ModuleManager::getVersion('mobile'),
			'target' => md5($userId . \CMain::GetServerUniqID()),
			'newStyleSupported' => true,
			'tabs' => $tabManager->getActiveTabsData(),
			'user' => [
				'type' => 'guest',
				'avatar' => $profile->getAvatar(),
				'theme' => $themeProvider->getCurrentTheme(),
			],
			'services' => $this->buildServices($userId, $pullConfig),
			'canTakeScreenshot' => $canTakeScreenshot,
			'canCopyText' => $canCopyText,
			'appmap' => $this->buildAppMap($moduleVersion),
			'featureFlags' => [
				'disableAvaMenu' => Feature::isEnabled(MenuFeature::class),
			],
		];

		if ($token !== null)
		{
			$response['token'] = $token;
		}

		return $response;
	}

	private function getModuleVersion(): string
	{
		$version = defined('MOBILE_MODULE_VERSION') ? MOBILE_MODULE_VERSION : 'default';
		if (($_COOKIE['IS_WKWEBVIEW'] ?? null) === 'Y')
		{
			$version .= '_wkwebview';
		}

		return $version;
	}

	/**
	 * @return array{0: bool, 1: bool} [canCopyText, canTakeScreenshot]
	 */
	private function resolveMobilePermissions(): array
	{
		$canCopyText = true;
		$canTakeScreenshot = true;

		$serviceLocator = ServiceLocator::getInstance();
		if ($serviceLocator->has(self::SERVICE_MOBILE_APP_SETTINGS))
		{
			/** @var MobileAppSettings $mobileSettings */
			$mobileSettings = $serviceLocator->get(self::SERVICE_MOBILE_APP_SETTINGS);
			if ($mobileSettings->isReady())
			{
				$canCopyText = $mobileSettings->canCopyText();
				$canTakeScreenshot = $mobileSettings->canTakeScreenshot();
			}
		}

		return [$canCopyText, $canTakeScreenshot];
	}

	private function buildServices(int $userId, mixed $pullConfig): array
	{
		$services = [
			$this->buildService(self::COMPONENT_COMMUNICATION, [
				'USER_ID' => $userId,
				'SITE_ID' => SITE_ID,
				'LANGUAGE_ID' => LANGUAGE_ID,
				'PULL_CONFIG' => $pullConfig,
			]),
			$this->buildService(self::COMPONENT_BACKGROUND, [
				'USER_ID' => $userId,
				'SITE_ID' => SITE_ID,
				'LANGUAGE_ID' => LANGUAGE_ID,
			]),
		];

		$callService = $this->buildCallService($userId);
		if ($callService !== null)
		{
			$services[] = $callService;
		}

		return $services;
	}

	/**
	 * The call:calls runtime for the guest session: without it the guest has neither the incoming-call
	 * handler ({@see \Call::incoming}) nor the ImMobile.CallManager:guestIdentified subscriber. Telephony
	 * itself stays off for guests (the voximplant block is emitted in its disabled state; the guest never
	 * initiates a call). Degrades to null — and the guest checkout keeps working — when call is unavailable.
	 */
	private function buildCallService(int $userId): ?array
	{
		if (!Loader::includeModule('call'))
		{
			return null;
		}

		// Merge order mirrors the employee build (mobile.data checkout): base identity → disabled
		// voximplant block → call options. callLogService appears in both the voximplant block and
		// getMobileOptions(); the latter wins, as it does for employees.
		$params = array_merge(
			[
				'userId' => $userId,
				'isAdmin' => false,
				'siteDir' => SITE_DIR,
			],
			$this->buildDisabledVoximplantOptions(),
			Settings::getMobileOptions(),
		);

		return $this->buildService(self::COMPONENT_CALLS, $params, 'JNUIComponent', self::COMPONENT_CALLS_SCRIPT);
	}

	/**
	 * The voximplant block of the call:calls contract in its disabled state. Guests never place
	 * telephony calls, but the component reads these keys via BX.componentParameters.get(), so the
	 * full set is emitted explicitly rather than left to client-side defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function buildDisabledVoximplantOptions(): array
	{
		return [
			'voximplantInstalled' => false,
			'voximplantServer' => '',
			'voximplantLogin' => '',
			'canPerformCalls' => false,
			'lines' => [],
			'defaultLineId' => '',
			'callLogService' => '',
		];
	}

	private function buildService(
		string $componentCode,
		array $params,
		string $name = 'JSComponent',
		?string $scriptComponent = null
	): array
	{
		return [
			'scriptPath' => JanativeManager::getComponentPath($scriptComponent ?? $componentCode),
			'params' => $params,
			'name' => $name,
			'componentCode' => $componentCode,
		];
	}

	private function buildAppMap(string $moduleVersion): array
	{
		return [
			'main' => [
				'url' => SITE_DIR . 'mobile/index.php?version=' . $moduleVersion,
				'bx24ModernStyle' => true,
			],
			'menu' => ['url' => SITE_DIR . 'mobile/left.php?version=' . $moduleVersion],
			'notification' => ['url' => SITE_DIR . 'mobile/im/notify.php'],
		];
	}

	/**
	 * Standard guest error for native: list of {code, message}. Code = im reason
	 * (AuthError/SharingLinkError/ChatError); empty list falls back to AUTHORIZE_ERROR.
	 * guestSessionInvalid (set only when true) → native drops the local guest account.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function buildError(array $errors, bool $guestSessionInvalid = false): array
	{
		$payload = [];
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			$payload[] = [
				'code' => $code,
				'message' => $this->errorMessage($guestSessionInvalid ? AuthError::GUEST_SESSION_TERMINATED : $code),
			];
		}

		if ($payload === [])
		{
			$payload[] = [
				'code' => AuthError::AUTHORIZE_ERROR,
				'message' => $this->errorMessage($guestSessionInvalid ? AuthError::GUEST_SESSION_TERMINATED : AuthError::AUTHORIZE_ERROR),
			];
		}

		$data = [
			'status' => 'failed',
			'bitrix_sessid' => bitrix_sessid(),
			'errors' => $payload,
		];

		if ($guestSessionInvalid)
		{
			$data['guestSessionInvalid'] = true;
		}

		return $data;
	}

	private function errorMessage(string $code): string
	{
		static $loaded = false;
		if (!$loaded)
		{
			Loc::loadMessages(__FILE__);
			$loaded = true;
		}

		$key = 'ERROR_' . $code;

		return Loc::getMessage($key)
			?: Loc::getMessage('ERROR_AUTHORIZE_ERROR')
			?: $code;
	}
}
