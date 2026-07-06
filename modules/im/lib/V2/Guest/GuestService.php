<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\Recent;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Auth\AuthenticationService;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Guest\Auth\AuthorizationService;
use Bitrix\Im\V2\Guest\Auth\AuthResult;
use Bitrix\Im\V2\Guest\Auth\JoinStatus;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\Auth\UserCreator;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
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
	 * Chat literal types allowed for guest join.
	 *
	 * Only plain group and open chats are allowed; subtypes that share the same literal
	 * but carry a non-empty ENTITY_TYPE (videoconference, tasks, CRM, sonet group, general, etc.)
	 * are filtered out by {@see self::isChatAllowed()}.
	 */
	protected const ALLOWED_CHAT_TYPES = [
		Chat::IM_TYPE_CHAT,
		Chat::IM_TYPE_OPEN,
	];

	protected static ?self $instance = null;

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
		$linkResult = $this->resolveGuestLink($code);
		if (!$linkResult->isSuccess())
		{
			return (new AuthResult())->addErrors($linkResult->getErrors());
		}

		/** @var GuestChatLink $link */
		$link = $linkResult->getResult();
		$chat = $linkResult->getChat();

		$result = $this->resolveUser($token, $name);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->setChat($chat);

		if ($result->getJoinStatus() !== JoinStatus::RETURNING_GUEST)
		{
			$incrementResult = $link->incrementUsesCount();
			if (!$incrementResult->isSuccess())
			{
				return $result->addErrors($incrementResult->getErrors());
			}
		}

		$userId = $result->getUser()?->getId() ?? Locator::getContext()->getUserId();
		$addResult = $this->addUserToChat($chat, $userId);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result;
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
		if (!Features::isChatWithGuestsAvailable())
		{
			return (new AuthResult())->addError(new AuthError(AuthError::FEATURE_DISABLED));
		}

		$link = SharingLinkFactory::getInstance()->getLinkByCode($code);
		if (!($link instanceof GuestChatLink))
		{
			return (new AuthResult())->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		if ($link->isRevoked())
		{
			return (new AuthResult())->addError(new SharingLinkError(SharingLinkError::REVOKED));
		}

		if ($link->isExhausted())
		{
			return (new AuthResult())->addError(new AuthError(AuthError::USES_LIMIT_REACHED));
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

	private function addUserToChat(Chat $chat, int $userId): Result
	{
		$chat->addUsers([$userId], new AddUsersConfig(hideHistory: false, withMessage: false));

		$relation = $chat->getRelationByUserId($userId);
		if ($relation === null)
		{
			return (new Result())->addError(new AuthError(AuthError::JOIN_CHAT_ERROR));
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
			return $result->addError(new AuthError(AuthError::INVALID_NAME));
		}

		$cUser = new \CUser();
		$updateResult = $cUser->Update($userId, ['NAME' => $sanitizedName]);
		if (!$updateResult)
		{
			return $result->addError(new AuthError(AuthError::UPDATE_NAME_ERROR));
		}

		return $result;
	}

	public function sendGuestLinkSystemMessage(Chat $chat, int $userId, string $locKey, array $replacements = []): void
	{
		$user = User::getInstance($userId);
		$messageText = Loc::getMessage(
			$locKey . '_' . $user->getGender(),
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

		return empty($chat->getEntityType());
	}
}
