<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Pull\UserLogout;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Cookie;

/**
 * Session and cookie management for guest users.
 *
 * @see AuthenticationService for token → user lookup.
 * @see \Bitrix\Im\V2\Guest\GuestService for high-level guest operations.
 */
class AuthorizationService
{
	public const COOKIE_HASH = 'BITRIX_IM_GUEST_HASH';
	public const COOKIE_INVITE_CODE = 'BITRIX_IM_GUEST_INVITE_CODE';

	protected static ?self $instance = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function authorize(User $user): Result
	{
		$result = new Result();

		if (!$user->isExist())
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		if ($user->getExternalAuthId() !== UserGuest::AUTH_ID)
		{
			return $result->addError(new AuthError(AuthError::NOT_GUEST));
		}

		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		if ((int)$globalUser->GetID() === $user->getId())
		{
			return $result;
		}

		if (!$globalUser->Authorize($user->getId(), false, false, GuestApplication::ID))
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		$this->setCookies($globalUser->GetParam('XML_ID'));

		return $result;
	}

	public function setInviteCode(string $code): void
	{
		if (!InviteCode::isValid($code))
		{
			return;
		}

		// Invite code is public by design (it lives in the /guest/{code} URL). We expose it
		// to JS on purpose so the web UserLogout handler can compare params.deactivatedCodes
		// against the guest's own code before redirecting. The auth secret stays HttpOnly via
		// {@see self::COOKIE_HASH}.
		Application::getInstance()->getContext()->getResponse()->addCookie(
			$this->buildPersistentCookie(self::COOKIE_INVITE_CODE, $code, httpOnly: false)
		);
	}

	/**
	 * Cookies must be cleared together with Logout — a leftover BITRIX_IM_GUEST_HASH
	 * would re-authenticate the guest on the next request.
	 *
	 * @param list<string> $deactivatedCodes
	 */
	public function terminate(array $deactivatedCodes = []): void
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return;
		}

		$userId = (int)$globalUser->GetID();
		if ($userId > 0)
		{
			(new UserLogout([$userId], $deactivatedCodes))->send();
		}

		$this->clearGuestCookies();
		$globalUser->Logout();
	}

	/**
	 * Self-action: terminate iff the affected chat is the cookie chat (cookies are in the request).
	 * External kick: deactivate the user (kills the token) and push userLogout.
	 */
	public function invalidateGuestUser(int $userId, int $chatId): void
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		$user = User::getInstance($userId);
		if (!$user->isExist() || !$user->isGuest())
		{
			return;
		}

		if (Locator::getContext()->getUserId() === $userId)
		{
			$cookieCode = $this->getCookieCodeIfMatchesChat($chatId);
			if ($cookieCode !== null)
			{
				$this->terminate([$cookieCode]);
			}

			return;
		}

		$primaryCode = $this->getPrimaryGuestCodeForChat($chatId);
		$deactivatedCodes = $primaryCode !== null ? [$primaryCode] : [];

		(new UserLogout([$userId], $deactivatedCodes))->send();
		$this->deactivateGuestUser($userId);
	}

	protected function setCookies(string $xmlId): void
	{
		$hash = $this->extractHashFromXmlId($xmlId);
		if ($hash === null)
		{
			return;
		}

		Application::getInstance()->getContext()->getResponse()->addCookie(
			$this->buildPersistentCookie(self::COOKIE_HASH, $hash)
		);
	}

	protected function extractHashFromXmlId(string $xmlId): ?string
	{
		$prefix = UserGuest::AUTH_ID . '|';

		return str_starts_with($xmlId, $prefix) ? mb_substr($xmlId, mb_strlen($prefix)) : null;
	}

	/** Caller must guarantee the current request belongs to the affected guest. */
	private function getCookieCodeIfMatchesChat(int $chatId): ?string
	{
		$code = InviteCode::createFromRequest();
		if ($code === null)
		{
			return null;
		}

		$link = SharingLinkFactory::getInstance()->getLinkByCode($code->getValue());
		if (!($link instanceof GuestChatLink) || $link->getChatId() !== $chatId)
		{
			return null;
		}

		return $code->getValue();
	}

	/** Best-guess code when the guest's own cookies aren't accessible (external kick). */
	private function getPrimaryGuestCodeForChat(int $chatId): ?string
	{
		$link = SharingLinkFactory::getInstance()
			->getActivePrimaryLinkByEntityFields(LinkEntityType::GuestChat, (string)$chatId)
		;

		return $link?->getCode();
	}

	/** Same pattern as {@see \Bitrix\Im\V2\Guest\CleanupService}: ACTIVE='N' kills token, Authorize and relation/recent queries. */
	private function deactivateGuestUser(int $userId): void
	{
		(new \CUser())->Update($userId, ['ACTIVE' => 'N']);
	}

	private function buildPersistentCookie(string $name, string $value, bool $httpOnly = true): Cookie
	{
		$cookie = new Cookie($name, $value, null, false);
		$cookie->setHttpOnly($httpOnly);

		return $cookie;
	}

	private function clearGuestCookies(): void
	{
		$response = Application::getInstance()->getContext()->getResponse();
		$pastExpire = time() - 3600;

		foreach ([self::COOKIE_HASH, self::COOKIE_INVITE_CODE] as $name)
		{
			$cookie = new Cookie($name, '', $pastExpire, false);
			$cookie->setHttpOnly(true);
			$response->addCookie($cookie);
		}
	}
}
