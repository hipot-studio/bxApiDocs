<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Integration\Notifications\NotificationService;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\Dto\CreateDto;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\GuestInviteService;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;

/**
 * Service for managing guest chat sharing links.
 *
 * Provides public API for generating, regenerating and revoking guest links.
 * Can be used by external modules (calendar, video calls, etc.).
 */
class GuestLinkService
{
	private static ?self $instance = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get existing active link or generate a new one for a chat.
	 *
	 * @param Chat $chat
	 * @param int $authorId
	 * @param bool $withMessage Send a system message into the chat when the link is fresh (no uses yet).
	 * @return Result<GuestChatLink>
	 */
	public function getOrCreateLink(Chat $chat, int $authorId, bool $withMessage = true): Result
	{
		$result = new Result();

		if (!Features::isChatWithGuestsAvailable())
		{
			return $result->addError(new AuthError(AuthError::FEATURE_DISABLED));
		}

		$factory = SharingLinkFactory::getInstance();
		$existingLink = $factory->getActiveIndividualLinkByEntityFields(
			LinkEntityType::GuestChat,
			(string)$chat->getId(),
			$authorId
		);

		if ($existingLink !== null)
		{
			$linkResult = $result->setResult($existingLink);
		}
		else
		{
			$dto = CreateDto::initForGuestChat(
				(string)$chat->getId(),
				$authorId,
				maxUses: GuestChatLink::SHARED_LINK_MAX_USES
			);
			$linkResult = $factory->generateLink($dto);
		}

		if (
			$withMessage
			&& $linkResult->isSuccess()
			&& $linkResult->getResult()->getUsesCount() === 0
		)
		{
			GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_GENERATED');
		}

		return $linkResult;
	}

	/**
	 * Generate a new link, revoking any existing one.
	 *
	 * @param Chat $chat
	 * @param int $authorId
	 * @param bool $withMessage Send a system message into the chat on success.
	 * @return Result<GuestChatLink>
	 */
	public function regenerateLink(Chat $chat, int $authorId, bool $withMessage = true): Result
	{
		$result = new Result();

		if (!Features::isChatWithGuestsAvailable())
		{
			return $result->addError(new AuthError(AuthError::FEATURE_DISABLED));
		}

		$dto = CreateDto::initForGuestChat(
			(string)$chat->getId(),
			$authorId,
			maxUses: GuestChatLink::SHARED_LINK_MAX_USES
		);

		$generateResult = SharingLinkFactory::getInstance()->generateLink(dto: $dto, forceGenerate: true);

		if ($generateResult->isSuccess() && $withMessage)
		{
			GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_REGENERATED');
		}

		return $generateResult;
	}

	/**
	 * Revoke the active individual link for a chat.
	 *
	 * @param Chat $chat
	 * @param int $authorId
	 * @param bool $withMessage Send a system message into the chat on success.
	 * @return Result
	 */
	public function revokeLink(Chat $chat, int $authorId, bool $withMessage = true): Result
	{
		$result = new Result();

		if (!Features::isChatWithGuestsAvailable())
		{
			return $result->addError(new AuthError(AuthError::FEATURE_DISABLED));
		}

		$factory = SharingLinkFactory::getInstance();
		$existingLink = $factory->getActiveIndividualLinkByEntityFields(
			LinkEntityType::GuestChat,
			(string)$chat->getId(),
			$authorId
		);

		if ($existingLink === null)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));
		}

		$revokeResult = $existingLink->revoke();

		if ($revokeResult->isSuccess() && $withMessage)
		{
			GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_REVOKED');
		}

		return $revokeResult;
	}

	/**
	 * Create personalized guest link and send invitation by email.
	 *
	 * @param Chat $chat
	 * @param int $authorId
	 * @param string $email
	 * @param string|null $name
	 * @param bool $withMessage Send a system message into the chat on success.
	 * @return Result<GuestChatLink>
	 */
	public function inviteByEmail(
		Chat $chat,
		int $authorId,
		string $email,
		?string $name = null,
		bool $withMessage = true,
	): Result
	{
		$inviteResult = GuestInviteService::getInstance()->inviteByEmail($chat, $authorId, $email, $name);

		if ($inviteResult->isSuccess() && $withMessage)
		{
			GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_INVITED_EMAIL', [
				'#CONTACT#' => str_replace(['[', ']'], ['&#91;', '&#93;'], $email),
			]);
		}

		return $inviteResult;
	}

	/**
	 * Create personalized guest link and send invitation by phone number.
	 *
	 * @param Chat $chat
	 * @param int $authorId
	 * @param string $phone
	 * @param string|null $name
	 * @param bool $withMessage Send a system message into the chat on success.
	 * @return Result<GuestChatLink>
	 */
	public function inviteByPhoneNumber(
		Chat $chat,
		int $authorId,
		string $phone,
		?string $name = null,
		bool $withMessage = true,
	): Result
	{
		$inviteResult = GuestInviteService::getInstance()->inviteByPhoneNumber($chat, $authorId, $phone, $name);

		if ($inviteResult->isSuccess() && $withMessage)
		{
			$formattedPhone = NotificationService::formatPhoneE164($phone) ?? $phone;
			GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_INVITED_SMS', [
				'#CONTACT#' => $formattedPhone,
			]);
		}

		return $inviteResult;
	}
}
