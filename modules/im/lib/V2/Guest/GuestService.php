<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Auth\AuthenticationService;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Guest\Auth\AuthorizationService;
use Bitrix\Im\V2\Guest\Auth\AuthResult;
use Bitrix\Im\V2\Guest\Auth\InviteCode;
use Bitrix\Im\V2\Guest\Auth\JoinStatus;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\Auth\UserCreator;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Im\V2\SharingLink\SharingLinkMemberService;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;

/**
 * High-level service for guest user operations.
 *
 * Orchestrates the complete guest flow: user creation, authentication, and chat joining.
 * Delegates to AuthenticationService for token-based auth and AuthorizationService for session auth.
 */
class GuestService
{
	/**
	 * URI prefix served by the im.router component for guest invite links (/guest/{code}).
	 */
	public const ROUTE_PATH = '/guest/';

	/**
	 * Chat literal types allowed for guest join.
	 *
	 * Plain group and open chats are allowed by default; chats with a non-empty ENTITY_TYPE
	 * are filtered by {@see self::isChatAllowed()} unless that ENTITY_TYPE is whitelisted in
	 * {@see self::ALLOWED_ENTITY_TYPES} (e.g. calendar meeting chats).
	 */
	protected const ALLOWED_CHAT_TYPES = [
		Chat::IM_TYPE_CHAT,
		Chat::IM_TYPE_OPEN,
	];

	/**
	 * Non-empty ENTITY_TYPE values that bypass the plain-chat restriction.
	 * Plain chats (empty ENTITY_TYPE) are always allowed; bound chats are joinable
	 * by guests only if their ENTITY_TYPE appears here.
	 */
	protected const ALLOWED_ENTITY_TYPES = [
		Chat\ExtendedType::Calendar->value,
	];

	protected static ?self $instance = null;

	/** @var array<string, GuestChatLink|null> per-request memo for getLinkByCode resolution */
	private array $guestChatLinkByCode = [];

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Entry point for guest flow: validate link, authenticate/register guest, join chat.
	 *
	 * @param string $code Invite link code
	 * @param Token|null $token Guest token from request (null for new guests)
	 * @param string|null $name Optional guest name
	 */
	public function joinByCode(string $code, ?Token $token = null, ?string $name = null): AuthResult
	{
		// A format-valid but stale token (guest kicked/deactivated, session already gone) must not
		// dead-end in an auth redirect: drop the stale cookies and continue as a brand-new guest.
		if ($token !== null && !$this->isGuestTokenValid($token))
		{
			AuthorizationService::getInstance()->clearGuestCookies();
			$token = null;
		}

		// Same for a stale PHP session: deactivation is lazy, so a kicked/deleted guest may still
		// be authorized here — must not resurrect as a returning guest.
		$contextUserId = Locator::getContext()->getUserId();
		if ($contextUserId > 0)
		{
			$contextUser = User::getInstance($contextUserId);
			if ($contextUser instanceof UserGuest && (!$contextUser->isExist() || !$contextUser->isActive()))
			{
				AuthorizationService::getInstance()->invalidateCurrentGuestSession();
			}
		}

		$linkResult = $this->resolveGuestLink($code);
		if (!$linkResult->isSuccess())
		{
			return (new AuthResult())->addErrors($linkResult->getErrors());
		}

		/** @var GuestChatLink $link */
		$link = $linkResult->getResult();
		$chat = $linkResult->getChat();

		// Newcomers can't use exhausted/expired links; returning guests bypass both (only
		// revocation stops them, checked in resolveGuestLink).
		if ($this->isNewGuestCandidate($token))
		{
			if ($link->isExhausted())
			{
				return (new AuthResult())->addError(new SharingLinkError(SharingLinkError::USES_LIMIT_REACHED));
			}

			if ($link->isExpired())
			{
				return (new AuthResult())->addError(new SharingLinkError(SharingLinkError::EXPIRED));
			}
		}

		$result = $this->resolveUser($token, $name);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->setChat($chat);

		$userId = $result->getUser()?->getId() ?? Locator::getContext()->getUserId();

		// An exhausted link must not let a returning guest with a valid token piggyback
		// onto a chat they never joined. Existing chat members retain idempotent re-entry.
		if (
			$link->isExhausted()
			&& $result->getJoinStatus() === JoinStatus::RETURNING_GUEST
			&& $chat->getRelationByUserId($userId) === null
		)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::USES_LIMIT_REACHED));
		}

		// A guest kicked by this link's author is blocked from re-entering through it, even
		// though the link itself stays live for everyone else.
		if (
			$result->getJoinStatus() !== JoinStatus::PORTAL_USER
			&& SharingLinkMemberService::getInstance()->isBlockedForLink((int)($link->getId() ?? 0), $userId)
		)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::ACCESS_DENIED));
		}

		if ($result->getJoinStatus() === JoinStatus::NEW_GUEST)
		{
			$incrementResult = $link->incrementUsesCount();
			if (!$incrementResult->isSuccess())
			{
				return $result->addErrors($incrementResult->getErrors());
			}
		}

		$addResult = $this->addUserToChat($chat, $userId, $link);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		(new ChatAnalytics($chat))->addJoinChatByGuestLink($link);

		if ($result->getJoinStatus() !== JoinStatus::PORTAL_USER)
		{
			AuthorizationService::getInstance()->setInviteCode($link->getCode());
			// Returning guests skip authorize(), so a cleared HASH cookie would never heal otherwise.
			AuthorizationService::getInstance()->refreshGuestCookie();
		}

		return $result;
	}

	/**
	 * Valid while the hash token resolves to the authorized active user AND at least one joined
	 * link is still live; the invite-code cookie is not involved.
	 */
	public function isCurrentGuestSessionValid(): bool
	{
		$token = Token::createFromRequest();
		if ($token === null)
		{
			return false;
		}

		$guestId = Locator::getContext()->getUserId();
		if ($guestId <= 0 || AuthenticationService::getInstance()->findUserByToken($token) !== $guestId)
		{
			return false;
		}

		return $this->hasValidGuestAccess($guestId);
	}

	/**
	 * At least one joined link is still live (not revoked, feature on, still a member,
	 * inviter may invite). Token-independent.
	 */
	public function hasValidGuestAccess(int $guestId): bool
	{
		if ($guestId <= 0)
		{
			return false;
		}

		$candidates = SharingLinkMemberService::getInstance()->getActiveLinkCandidatesByUser($guestId);
		if ($candidates === [])
		{
			return false;
		}

		// One membership query for all candidates — this runs on the hot auth path.
		$memberChatIds = $this->filterChatIdsGuestBelongsTo(
			$guestId,
			array_column($candidates, 'chatId'),
		);
		if ($memberChatIds === [])
		{
			return false;
		}

		foreach ($candidates as $candidate)
		{
			// Per-link feature/inviter checks run only on the membership-confirmed subset.
			if (isset($memberChatIds[$candidate['chatId']]) && $this->isGuestCodeValid($candidate['code']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<int> $chatIds
	 * @return array<int, true> chat ids the guest still has a relation in, as a lookup set
	 */
	private function filterChatIdsGuestBelongsTo(int $guestId, array $chatIds): array
	{
		$chatIds = array_values(array_unique(array_filter($chatIds, static fn (int $id): bool => $id > 0)));
		if ($chatIds === [])
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['CHAT_ID'])
			->where('USER_ID', $guestId)
			->whereIn('CHAT_ID', $chatIds)
			->fetchAll()
		;

		$memberChatIds = [];
		foreach ($rows as $row)
		{
			$memberChatIds[(int)$row['CHAT_ID']] = true;
		}

		return $memberChatIds;
	}

	/**
	 * Inviter (sharing-link author) of the current request, resolved from the invite-code
	 * cookie. The guest feature is gated on the inviter's flag, never on the guest's own —
	 * a guest user never has the per-user option set.
	 */
	public function getCurrentInviterId(): ?int
	{
		// A persistent guest invite-code cookie can linger in a browser later used by a
		// portal user; the inviter context must apply only to guests, never override a
		// real portal user's own feature flag.
		$userId = Locator::getContext()->getUserId();
		if ($userId > 0 && !User::getInstance($userId)->isGuest())
		{
			return null;
		}

		$code = AuthorizationService::getInstance()->getInviteCode();
		if ($code === null || $code === '')
		{
			return null;
		}

		$link = $this->resolveGuestChatLink($code);
		if ($link === null)
		{
			return null;
		}

		$inviterId = (int)($link->getAuthorId() ?? 0);

		return $inviterId > 0 ? $inviterId : null;
	}

	/**
	 * Resolves a guest chat link by code, memoized per request (GuestService is a
	 * request-scoped singleton). Returns null when the code maps to no link or to a
	 * non-guest link.
	 */
	private function resolveGuestChatLink(string $code): ?GuestChatLink
	{
		if (array_key_exists($code, $this->guestChatLinkByCode))
		{
			return $this->guestChatLinkByCode[$code];
		}

		$link = SharingLinkFactory::getInstance()->getLinkByCode($code);
		$this->guestChatLinkByCode[$code] = $link instanceof GuestChatLink ? $link : null;

		return $this->guestChatLinkByCode[$code];
	}

	/**
	 * Full guest-session validity: valid code + valid token + membership in the link's chat;
	 * the first error code carries the reason. Expiration/exhaustion guard new joins only,
	 * $expectedUserId guards against a token/PHP-session mismatch.
	 */
	public function validateGuestSession(string $code, Token $token, ?int $expectedUserId = null): Result
	{
		$linkResult = $this->resolveValidGuestLink($code);
		if (!$linkResult->isSuccess())
		{
			return $linkResult;
		}

		$guestResult = $this->resolveGuest($token, $expectedUserId);
		if (!$guestResult->isSuccess())
		{
			return $guestResult;
		}

		/** @var GuestChatLink $link */
		$link = $linkResult->getResult();
		$guestId = $guestResult->getResult();
		if (Chat::getInstance($link->getChatId())->getRelationByUserId($guestId) === null)
		{
			return (new Result())->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		return new Result();
	}

	public function isGuestSessionValid(string $code, Token $token, ?int $expectedUserId = null): bool
	{
		return $this->validateGuestSession($code, $token, $expectedUserId)->isSuccess();
	}

	/**
	 * Code side of session validity: feature, link, revocation, chat, inviter rights.
	 */
	public function isGuestCodeValid(string $code): bool
	{
		return $this->resolveValidGuestLink($code)->isSuccess();
	}

	/**
	 * Token side: resolves to an active guest ($expectedUserId — to exactly that user).
	 */
	public function isGuestTokenValid(?Token $token, ?int $expectedUserId = null): bool
	{
		return $this->resolveGuest($token, $expectedUserId)->isSuccess();
	}

	/**
	 * @return Result<int> active guest user id on success
	 */
	private function resolveGuest(?Token $token, ?int $expectedUserId = null): Result
	{
		$guestId = $token === null
			? null
			: AuthenticationService::getInstance()->findUserByToken($token)
		;
		if ($guestId === null)
		{
			return (new Result())->addError(new AuthError(AuthError::GUEST_NOT_FOUND));
		}

		if ($expectedUserId !== null && $guestId !== $expectedUserId)
		{
			return (new Result())->addError(new AuthError(AuthError::DIFFERENT_GUEST));
		}

		return (new Result())->setResult($guestId);
	}

	/**
	 * Link validity independent of any guest; membership is NOT checked here.
	 *
	 * @return Result<GuestChatLink> the valid link on success
	 */
	private function resolveValidGuestLink(string $code): Result
	{
		$link = $this->resolveGuestChatLink($code);
		if ($link === null)
		{
			return (new Result())->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		if ($link->isRevoked())
		{
			return (new Result())->addError(new SharingLinkError(SharingLinkError::REVOKED));
		}

		$inviterId = (int)($link->getAuthorId() ?? 0);
		if ($inviterId <= 0)
		{
			return (new Result())->addError(new SharingLinkError(SharingLinkError::ACCESS_DENIED));
		}

		if (!Features::isChatWithGuestsAvailable($inviterId))
		{
			return (new Result())->addError(new AuthError(AuthError::GUEST_FEATURE_DISABLED));
		}

		$chat = Chat::getInstance($link->getChatId());
		if (!$chat->isExist())
		{
			return (new Result())->addError(new ChatError(ChatError::NOT_FOUND));
		}

		$inviterRole = $chat->getRelationByUserId($inviterId)?->getRole() ?? Chat::ROLE_GUEST;
		$manageRights = $chat->getManageGuestInvites() ?? Chat::MANAGE_RIGHTS_MANAGERS;
		if (!Permission::compareRole($inviterRole, $manageRights))
		{
			return (new Result())->addError(new SharingLinkError(SharingLinkError::ACCESS_DENIED));
		}

		return (new Result())->setResult($link);
	}

	/**
	 * Whether the current request would result in creating a brand-new guest user.
	 * True only when there is no authorized user and no auth token in the request.
	 */
	private function isNewGuestCandidate(?Token $token): bool
	{
		if ($token !== null)
		{
			return false;
		}

		return Locator::getContext()->getUserId() <= 0;
	}

	/**
	 * Determine joining user: return portal user info or authenticate/register a guest.
	 */
	private function resolveUser(?Token $token, ?string $name): AuthResult
	{
		$userId = Locator::getContext()->getUserId();
		if ($userId > 0)
		{
			$user = User::getInstance($userId);
			if ($user instanceof UserGuest)
			{
				return (new AuthResult())
					->setUser($user)
					->setJoinStatus(JoinStatus::RETURNING_GUEST)
					;
			}

			return (new AuthResult())->setJoinStatus(JoinStatus::PORTAL_USER);
		}

		$authResult = $token !== null
			? AuthenticationService::getInstance()->authenticate($token)
			: $this->registerNewGuest($name)
		;

		if (!$authResult->isSuccess())
		{
			return $authResult;
		}

		$joinStatus = $token !== null ? JoinStatus::RETURNING_GUEST : JoinStatus::NEW_GUEST;

		return (new AuthResult())
			->setUser($authResult->getUser())
			->setToken($authResult->getToken())
			->setJoinStatus($joinStatus)
			;
	}

	/**
	 * Resolve and validate guest sharing link by code.
	 *
	 * @return Result<GuestChatLink>
	 */
	private function resolveGuestLink(string $code): Result
	{
		$link = SharingLinkFactory::getInstance()->getLinkByCode($code);
		if (!($link instanceof GuestChatLink))
		{
			return (new AuthResult())->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		if (!Features::isChatWithGuestsAvailable($link->getAuthorId()))
		{
			return (new AuthResult())->addError(new AuthError(AuthError::GUEST_FEATURE_DISABLED));
		}

		if ($link->isRevoked())
		{
			return (new AuthResult())->addError(new SharingLinkError(SharingLinkError::REVOKED));
		}

		$chat = Chat::getInstance($link->getChatId());

		if (!$this->isChatAllowed($chat))
		{
			return (new AuthResult())->addError(new AuthError(AuthError::CHAT_TYPE_NOT_ALLOWED));
		}

		return (new AuthResult())->setChat($chat)->setResult($link);
	}

	private function registerNewGuest(?string $name): AuthResult
	{
		$createResult = (new UserCreator())->create($name);
		if (!$createResult->isSuccess())
		{
			return (new AuthResult())->addErrors($createResult->getErrors());
		}

		$authResult = AuthorizationService::getInstance()->authorize($createResult->getUser());
		if (!$authResult->isSuccess())
		{
			return (new AuthResult())->addErrors($authResult->getErrors());
		}

		return (new AuthResult())
			->setUser($createResult->getUser())
			->setToken($createResult->getToken())
			;
	}

	private function addUserToChat(Chat $chat, int $userId, GuestChatLink $link): Result
	{
		$chat->addUsers([$userId], new AddUsersConfig(hideHistory: true, withMessage: true, sharingLink: $link));

		$relation = $chat->getRelationByUserId($userId);
		if ($relation === null)
		{
			return (new Result())->addError(new AuthError(AuthError::JOIN_CHAT_ERROR));
		}

		// Guests only: a portal user following a guest link must not land in the sidecar,
		// or a later revoke would kick a regular member.
		if (User::getInstance($userId)->isGuest())
		{
			SharingLinkMemberService::getInstance()->track(
				(int)($link->getId() ?? 0),
				$userId,
				$link::getEntityType()->value,
				(string)($link->getEntityId() ?? ''),
			);
		}

		Recent::addRecent($chat, $relation);

		return new Result();
	}

	/**
	 * Update the display name of the current guest user.
	 */
	public function setName(string $name): Result
	{
		$result = new Result();

		$userId = Locator::getContext()->getUserId();
		if ($userId <= 0)
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		$user = User::getInstance($userId);
		if (!($user instanceof UserGuest))
		{
			return $result->addError(new AuthError(AuthError::NOT_GUEST));
		}

		$sanitizedName = (new UserCreator())->sanitizeName($name);
		if ($sanitizedName === null)
		{
			return $result->addError(new AuthError(AuthError::GUEST_INVALID_NAME));
		}

		$cUser = new \CUser();
		$updateResult = $cUser->Update($userId, ['NAME' => $sanitizedName]);
		if (!$updateResult)
		{
			return $result->addError(new AuthError(AuthError::GUEST_UPDATE_NAME_ERROR));
		}

		return $result;
	}

	public function sendGuestLinkSystemMessage(Chat $chat, int $userId, string $locKey, array $replacements = []): void
	{
		$user = User::getInstance($userId);
		$gender = '_' . $user->getGender();

		if (preg_match('/^(.+)(_MSGVER_\d+)$/', $locKey, $matches) === 1)
		{
			$phraseCode = $matches[1] . $gender . $matches[2];
		}
		else
		{
			$phraseCode = $locKey . $gender;
		}

		$messageText = Loc::getMessage(
			$phraseCode,
			array_merge(['#USER_NAME#' => "[USER={$userId}][/USER]"], $replacements)
		);

		$message = (new Message())
			->setAuthorId($userId)
			->setChatId($chat->getId())
			->setMessage($messageText)
			->markAsSystem(true)
		;

		$chat->sendMessage($message, (new SendingConfig())->enableSkipCounterIncrements());
	}

	private function isChatAllowed(Chat $chat): bool
	{
		if (!in_array($chat->getType(), self::ALLOWED_CHAT_TYPES, true))
		{
			return false;
		}

		$entityType = $chat->getEntityType();

		return empty($entityType) || in_array($entityType, self::ALLOWED_ENTITY_TYPES, true);
	}

	/**
	 * Check whether the current HTTP request targets the guest router endpoint.
	 *
	 * @return bool
	 */
	public static function isOnGuestRoute(): bool
	{
		$request = Context::getCurrent()?->getRequest();
		if ($request === null)
		{
			return false;
		}

		$path = parse_url((string)$request->getRequestUri(), PHP_URL_PATH);

		return is_string($path) && str_starts_with($path, self::ROUTE_PATH);
	}
}
