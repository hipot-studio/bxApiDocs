<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Pull\UserLogout;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\SharingLinkMemberService;
use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
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

	/**
	 * Counts the exits from the guest scope {@see self::suspendCurrentGuestSession}. Kept against the guest
	 * and not in a session, because the session is not there to be read where it matters most: '/rest/'
	 * runs on a virtual one - a NullSessionHandler under a freshly generated id on every hit, see
	 * rest/services/rest/index.php - and that is exactly where the guest is authorized back from the
	 * token cookie. A mark in the session is invisible to REST, so it would not hold the exit at all.
	 */
	private const OPTION_CATEGORY = 'im';
	private const OPTION_SCOPE_EXIT_ATTEMPTS = 'guest_scope_exit_attempts';

	/**
	 * A returned authorization must not stop the exit, and must not let it loop either: an open tab of
	 * the guest chat can keep restoring SESS_AUTH for as long as it lives.
	 */
	private const SCOPE_EXIT_ATTEMPT_LIMIT = 3;

	protected static ?self $instance = null;

	private ?string $inviteCode = null;

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

		// The single point that writes SESS_AUTH for a guest, so the single point the suspension has to
		// hold: a suspended session must not be authorized back into the scope from the cookie.
		if ($this->isGuestScopeSuspended($user->getId()))
		{
			return $result->addError(new AuthError(AuthError::GUEST_SESSION_SUSPENDED));
		}

		if (!$globalUser->Authorize($user->getId(), false, false, GuestApplication::ID))
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		$this->setCookies($globalUser->GetParam('XML_ID'));

		return $result;
	}

	/**
	 * Re-issues the auth cookie for the already-authorized guest of the current request.
	 *
	 * A returning guest keeps their PHP session, so authorize() is skipped and the HASH cookie is
	 * never refreshed — a cleared/corrupted BITRIX_IM_GUEST_HASH then fails the next guard check
	 * ({@see \Bitrix\Im\V2\Guest\GuestService::isCurrentGuestSessionValid}) and tears the session
	 * down. Re-issuing on re-entry self-heals it, symmetric to {@see self::setInviteCode}.
	 * XML_ID is read from the DB, not GetParam(): a session-restored CUser may not carry it.
	 */
	public function refreshGuestCookie(): void
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return;
		}

		$userId = (int)$globalUser->GetID();
		if ($userId <= 0)
		{
			return;
		}

		$userData = UserTable::query()
			->setSelect(['XML_ID', 'EXTERNAL_AUTH_ID'])
			->where('ID', $userId)
			->fetch()
		;
		if ($userData && $userData['EXTERNAL_AUTH_ID'] === UserGuest::AUTH_ID)
		{
			$this->setCookies((string)$userData['XML_ID']);
		}
	}

	public function setInviteCode(string $code): void
	{
		if (!InviteCode::isValid($code))
		{
			return;
		}

		$this->inviteCode = $code;

		// HttpOnly: UserLogout is targeted by recipient now, so JS never reads this cookie.
		Application::getInstance()->getContext()->getResponse()->addCookie(
			$this->buildPersistentCookie(self::COOKIE_INVITE_CODE, $code)
		);
	}

	/**
	 * Invite code of the current request. Prefers the value set during this request
	 * ({@see self::setInviteCode}, e.g. guest checkout/join) so it is resolvable on the guest's
	 * FIRST entry — before the response cookie has round-tripped — then falls back to the cookie.
	 */
	public function getInviteCode(): ?string
	{
		if ($this->inviteCode !== null)
		{
			return $this->inviteCode;
		}

		return InviteCode::createFromRequest()?->getValue();
	}

	/**
	 * Ends a guest session for good: drop server-side auth and clear the guest cookies WITHOUT calling
	 * Logout(). Cookies must go with the auth slot: a leftover BITRIX_IM_GUEST_HASH would re-authenticate
	 * the guest. To end it while the guest stays themselves, use {@see self::suspendCurrentGuestSession}.
	 *
	 * Logout() is off limits for the whole guest contour, for two independent reasons:
	 *
	 * 1. The messenger fires a burst of concurrent requests, so the hot per-request guard
	 * ({@see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter}) must not call Logout():
	 * Logout()→regenerateId() rotates the file-backed session, and concurrent rotations of the
	 * same session race and make session_start() throw ("Could not start session by PHP").
	 *
	 * 2. Every guest path that ends a session runs inside the guest's own request, which owes a
	 * machine-readable response. Logout() raises main:OnAfterUserLogout, and on the cloud its
	 * bitrix24 subscriber answers with LocalRedirect to OAuth — the response body never ships.
	 *
	 * Server-side auth is still dropped: leaving SESS_AUTH in place keeps the guest authorized for
	 * auth-only endpoints outside the im guard (e.g. ui.entityselector), so a revoked guest could
	 * keep querying them until the session expires. Clearing SESS_AUTH is idempotent under the
	 * burst, unlike regenerateId() — so we deauthorize but skip the rotation.
	 *
	 * Consequence to keep in mind: the CUser object of the CURRENT request stays authorized, because
	 * only the session slot is cleared and CUser::$context survives. The next request (a fresh CUser)
	 * is unauthorized.
	 */
	public function invalidateCurrentGuestSession(): void
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return;
		}

		$this->notifyLogoutAndClearCookies($globalUser);
		$this->clearServerSideAuth();
	}

	/**
	 * Ends the guest session without touching a cookie: the identity has to survive, or the return
	 * through the invite link would create a second guest. Dropping SESS_AUTH alone does not end it -
	 * the page's ajax burst authorizes the guest back from that cookie - so the mark counts the exits.
	 *
	 * @return bool false when the attempts are spent and the caller has to let the request through
	 */
	public function suspendCurrentGuestSession(): bool
	{
		// Read before the authorization is dropped: afterwards there is no one left to mark.
		$guestId = $this->getCurrentGuestId();
		if ($guestId === null)
		{
			return false;
		}

		$attempts = $this->countScopeExits($guestId);
		if ($attempts >= self::SCOPE_EXIT_ATTEMPT_LIMIT)
		{
			return false;
		}

		$this->clearServerSideAuth();
		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::OPTION_SCOPE_EXIT_ATTEMPTS,
			$attempts + 1,
			false,
			$guestId
		);

		return true;
	}

	/**
	 * Re-opens the guest scope for a suspended guest and resets the attempts. Following the invite link is
	 * the one act that does it: the guest asks for the guest page itself, so no burst is left to guard the
	 * exit from.
	 */
	public function allowGuestReentry(int $userId): void
	{
		\CUserOptions::DeleteOption(self::OPTION_CATEGORY, self::OPTION_SCOPE_EXIT_ATTEMPTS, false, $userId);
	}

	/**
	 * Hands the lock of the general session back instead of holding it until shutdown. Call it only after
	 * everything this request writes to that session: a later write re-opens it in the 'separated' mode
	 * alone, in the shipped 'default' one that write is lost without a word.
	 */
	public function releaseSessionLock(): void
	{
		$session = Application::getInstance()->getSession();
		if ($session->isStarted())
		{
			$session->save();
		}
	}

	/** Answers on every entry point, with a session or without one - the reason the mark lives on the guest. */
	public function isGuestScopeSuspended(int $userId): bool
	{
		return $this->countScopeExits($userId) > 0;
	}

	private function countScopeExits(int $userId): int
	{
		return (int)\CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::OPTION_SCOPE_EXIT_ATTEMPTS,
			0,
			$userId
		);
	}

	/** Only a guest of our own scope is ever marked or suspended, whoever else the request belongs to. */
	private function getCurrentGuestId(): ?int
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null || !$globalUser->IsAuthorized())
		{
			return null;
		}

		if ($globalUser->GetParam('EXTERNAL_AUTH_ID') !== UserGuest::AUTH_ID)
		{
			return null;
		}

		return (int)$globalUser->GetID();
	}

	private function notifyLogoutAndClearCookies(\CUser $globalUser): void
	{
		$userId = (int)$globalUser->GetID();
		if ($userId > 0)
		{
			(new UserLogout([$userId]))->send();
		}

		$this->clearGuestCookies();
	}

	// CUser exposes no "logout without rotation", so we clear its kernel-session auth slot directly
	// (idempotent → race-free, unlike Logout()→regenerateId()). Belongs behind a main API later.
	private function clearServerSideAuth(): void
	{
		$kernelSession = Application::getInstance()->getKernelSession();
		$kernelSession['SESS_AUTH'] = [];
		unset($kernelSession['SESS_AUTH']);
	}

	/**
	 * A guest lost a chat membership (relation removal is done by Chat::deleteUser). While any
	 * live link remains they only lost this chat; otherwise the session ends — self-action invalidates
	 * the current session and clears cookies, external kick kills the token and pushes userLogout.
	 *
	 * Self-action deliberately avoids Logout(): it runs inside the guest's own request, which owes a
	 * controller response, and Logout() raises main:OnAfterUserLogout — on the cloud its bitrix24
	 * subscriber answers with LocalRedirect to OAuth and the response body is lost.
	 */
	public function invalidateGuestUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$user = User::getInstance($userId);
		if (!$user->isExist() || !$user->isGuest())
		{
			return;
		}

		if (SharingLinkMemberService::getInstance()->hasAnyActiveLink($userId))
		{
			return;
		}

		if (Locator::getContext()->getUserId() === $userId)
		{
			$this->invalidateCurrentGuestSession();

			return;
		}

		(new UserLogout([$userId]))->send();
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

	/** Public so a stale guest session can be dropped before re-entry ({@see \Bitrix\Im\V2\Guest\GuestService::joinByCode}). */
	public function clearGuestCookies(): void
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
