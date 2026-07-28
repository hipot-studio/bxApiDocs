<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Integration\Notifications\NotificationService;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Guest\Pull\UserLogout;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\Dto\CreateDto;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\GuestInviteService;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Main\Event;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Service for managing guest chat sharing links.
 *
 * Provides public API for generating, regenerating and revoking guest links.
 * Can be used by external modules (calendar, video calls, etc.).
 */
class GuestLinkService
{
	private const REVOKE_BATCH_SIZE = 1000;

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
	 * @return Result<GuestChatLink>
	 */
	public function getOrCreateLink(Chat $chat, int $authorId): Result
	{
		$result = new Result();

		if (!Features::isChatWithGuestsAvailable($authorId))
		{
			return $result->addError(new AuthError(AuthError::GUEST_FEATURE_DISABLED));
		}

		$factory = SharingLinkFactory::getInstance();
		$existingLink = $factory->getActiveIndividualLinkByEntityFields(
			LinkEntityType::GuestChat,
			(string)$chat->getId(),
			$authorId
		);

		if ($existingLink !== null)
		{
			return $result->setResult($existingLink);
		}

		return $factory->generateLink($this->buildIndividualLinkDto($chat, $authorId));
	}

	/**
	 * Generate a new link, revoking any existing one.
	 *
	 * @return Result<GuestChatLink>
	 */
	public function regenerateLink(Chat $chat, int $authorId, bool $withMessage = true): Result
	{
		$result = new Result();

		if (!Features::isChatWithGuestsAvailable($authorId))
		{
			return $result->addError(new AuthError(AuthError::GUEST_FEATURE_DISABLED));
		}

		$factory = SharingLinkFactory::getInstance();
		$existingLink = $factory->getActiveIndividualLinkByEntityFields(
			LinkEntityType::GuestChat,
			(string)$chat->getId(),
			$authorId
		);
		$deactivatedCode = $existingLink?->getCode();

		$generateResult = $factory->generateLink(
			dto: $this->buildIndividualLinkDto($chat, $authorId),
			forceGenerate: true,
		);

		if ($generateResult->isSuccess())
		{
			if ($deactivatedCode !== null)
			{
				$this->notifyGuestsCodesDeactivated($chat, [$deactivatedCode]);
			}
			if ($withMessage)
			{
				GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_REGENERATED_MSGVER_1');
			}
		}

		return $generateResult;
	}

	/**
	 * Revoke the active individual link for a chat.
	 */
	public function revokeLink(Chat $chat, int $authorId, bool $withMessage = true): Result
	{
		$result = new Result();

		if (!Features::isChatWithGuestsAvailable($authorId))
		{
			return $result->addError(new AuthError(AuthError::GUEST_FEATURE_DISABLED));
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

		$deactivatedCode = $existingLink->getCode();
		$revokeResult = $existingLink->revoke();

		if ($revokeResult->isSuccess())
		{
			if ($deactivatedCode !== null)
			{
				$this->notifyGuestsCodesDeactivated($chat, [$deactivatedCode]);
			}
			if ($withMessage)
			{
				GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_REVOKED');
			}
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
		$inviteResult = GuestInviteService::getInstance()->inviteByEmail(
			$chat,
			$authorId,
			$email,
			$name,
			$this->resolveDateExpire($chat),
		);

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
		$inviteResult = GuestInviteService::getInstance()->inviteByPhoneNumber(
			$chat,
			$authorId,
			$phone,
			$name,
			$this->resolveDateExpire($chat),
		);

		if ($inviteResult->isSuccess() && $withMessage)
		{
			$formattedPhone = NotificationService::formatPhoneE164($phone) ?? $phone;
			GuestService::getInstance()->sendGuestLinkSystemMessage($chat, $authorId, 'IM_CHAT_GUEST_LINK_INVITED_SMS', [
				'#CONTACT#' => $formattedPhone,
			]);
		}

		return $inviteResult;
	}

	private function buildIndividualLinkDto(Chat $chat, int $authorId): CreateDto
	{
		return CreateDto::initForGuestChat(
			(string)$chat->getId(),
			$authorId,
			maxUses: GuestChatLink::SHARED_LINK_MAX_USES,
			dateExpire: $this->resolveDateExpire($chat),
		);
	}

	/**
	 * Calendar meeting chats keep their invite link alive indefinitely — the meeting may be
	 * scheduled far in the future, so the default 1-day TTL would expire before it starts.
	 */
	private function resolveDateExpire(Chat $chat): ?DateTime
	{
		if ($chat->getEntityType() === ExtendedType::Calendar->value)
		{
			return null;
		}

		return GuestChatLink::getDefaultDateExpire();
	}

	/**
	 * Called when a chat's MANAGE_GUEST_INVITES tightens (e.g. MEMBER→MANAGER, MANAGER→OWNER).
	 * Revokes every active guest link whose author no longer satisfies the new threshold.
	 * Silent: no system message, no per-link {@see \Bitrix\Im\V2\Pull\Event\SharingLink\SharingLinkUpdate}.
	 */
	public function onManageGuestInvitesChanged(Chat $chat, string $oldRights, string $newRights): void
	{
		if (!Chat::isManageRightsTightened($oldRights, $newRights))
		{
			return;
		}

		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0)
		{
			return;
		}

		$links = SharingLinkTable::query()
			->setSelect(['ID', 'AUTHOR_ID', 'CODE'])
			->where('ENTITY_TYPE', LinkEntityType::GuestChat->value)
			->where('ENTITY_ID', (string)$chatId)
			->where('IS_REVOKED', 'N')
			->fetchCollection()
		;
		if ($links->count() === 0)
		{
			return;
		}

		$linksByAuthor = [];
		foreach ($links as $link)
		{
			$linksByAuthor[$link->getAuthorId()][] = $link;
		}

		$relations = $chat->getRelationsByUserIds(array_keys($linksByAuthor));

		$ineligibleLinkIds = [];
		$deactivatedCodes = [];
		foreach ($linksByAuthor as $authorId => $authorLinks)
		{
			$authorRole = $relations->getByUserId($authorId, $chatId)?->getRole() ?? Chat::ROLE_GUEST;
			if (Permission::compareRole($authorRole, $newRights))
			{
				continue;
			}
			foreach ($authorLinks as $link)
			{
				$ineligibleLinkIds[] = $link->getId();
				$deactivatedCodes[] = $link->getCode();
			}
		}
		if ($ineligibleLinkIds === [])
		{
			return;
		}

		SharingLinkTable::updateMulti($ineligibleLinkIds, ['IS_REVOKED' => 'Y'], true);

		$this->notifyGuestsCodesDeactivated($chat, $deactivatedCodes);
	}

	public function onChatMemberDeleted(Chat $chat, int $userId): void
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0 || $userId <= 0)
		{
			return;
		}

		$links = SharingLinkTable::query()
			->setSelect(['ID', 'CODE'])
			->where('ENTITY_TYPE', LinkEntityType::GuestChat->value)
			->where('ENTITY_ID', (string)$chatId)
			->where('AUTHOR_ID', $userId)
			->where('IS_REVOKED', 'N')
			->fetchCollection()
		;
		if ($links->count() === 0)
		{
			return;
		}

		$ids = [];
		$deactivatedCodes = [];
		foreach ($links as $link)
		{
			$ids[] = $link->getId();
			$deactivatedCodes[] = $link->getCode();
		}

		SharingLinkTable::updateMulti($ids, ['IS_REVOKED' => 'Y'], true);

		$this->notifyGuestsCodesDeactivated($chat, $deactivatedCodes);
	}

	/**
	 * Event handler for `main:OnAfterSetOption_chat_with_guests_available`.
	 * Registered in {@see \im::DoInstall()}.
	 */
	public static function onChatWithGuestsOptionChanged(Event $event): void
	{
		if ($event->getParameter('value') !== 'N')
		{
			return;
		}

		self::getInstance()->onFeatureDisabled();
	}

	/**
	 * One-shot cleanup on feature disable: synchronously push userLogout to all active
	 * guests (cheap — indexed lookup + one Pull batch) so their open tabs redirect right
	 * away, and defer the heavy bulk-revoke UPDATE to a one-shot agent so the Save-handler
	 * does not block. Security holds in the meantime via the lazy check
	 * {@see Features::isChatWithGuestsAvailable()} inside {@see GuestService::isGuestSessionValid}.
	 */
	private function onFeatureDisabled(): void
	{
		$this->logoutAllActiveGuests();
		self::scheduleRevokeAllGuestLinksAgent();
	}

	/**
	 * Chunked one-shot agent. The $lastSeenId cursor is encoded in the agent name on
	 * each reschedule — keeps every batch's SELECT to a flat O(B) PRIMARY range scan
	 * instead of O(N) re-scan over already-revoked rows.
	 * Returns its own (cursor-bumped) name to reschedule, or '' to unregister itself.
	 * If the feature got re-enabled while the cleanup was in progress — bail out.
	 */
	public static function revokeAllGuestLinksAgent(int $lastSeenId = 0): string
	{
		if (Features::isChatWithGuestsAvailable())
		{
			return '';
		}

		$nextCursor = self::getInstance()->revokeNextGuestLinksBatch($lastSeenId);
		if ($nextCursor === null)
		{
			return '';
		}

		return self::getRevokeAllGuestLinksAgentName($nextCursor);
	}

	private static function scheduleRevokeAllGuestLinksAgent(): void
	{
		\CAgent::AddAgent(
			self::getRevokeAllGuestLinksAgentName(),
			'im',
			'N',
			60,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset(), 'FULL'),
			existError: false,
		);
	}

	private static function getRevokeAllGuestLinksAgentName(int $lastSeenId = 0): string
	{
		return '\\' . self::class . "::revokeAllGuestLinksAgent({$lastSeenId});";
	}

	/**
	 * @return int|null the new cursor (max ID processed) when the batch was full and
	 * there may be more; null when nothing left to do.
	 */
	private function revokeNextGuestLinksBatch(int $lastSeenId): ?int
	{
		$links = SharingLinkTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE', LinkEntityType::GuestChat->value)
			->where('IS_REVOKED', 'N')
			->where('ID', '>', $lastSeenId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::REVOKE_BATCH_SIZE)
			->fetchCollection()
		;
		if ($links->count() === 0)
		{
			return null;
		}

		$ids = [];
		foreach ($links as $link)
		{
			$ids[] = $link->getId();
		}

		SharingLinkTable::updateMulti($ids, ['IS_REVOKED' => 'Y'], true);

		if (count($ids) < self::REVOKE_BATCH_SIZE)
		{
			return null;
		}

		return max($ids);
	}

	private function logoutAllActiveGuests(): void
	{
		$rows = UserTable::query()
			->setSelect(['ID'])
			->where('EXTERNAL_AUTH_ID', UserGuest::AUTH_ID)
			->where('ACTIVE', 'Y')
			->fetchAll()
		;

		$userIds = array_values(array_map(static fn (array $row): int => (int)$row['ID'], $rows));
		if ($userIds === [])
		{
			return;
		}

		(new UserLogout($userIds))->send();
	}

	/**
	 * Push deactivated invite codes to all active guests of the chat. We don't store
	 * who joined via which code, so the cast is chat-wide; guests sitting on a different
	 * live link will pass {@see \Bitrix\Im\V2\Guest\GuestService::isCurrentGuestSessionValid}
	 * on the next request and keep their session.
	 *
	 * @param list<string> $deactivatedCodes
	 */
	private function notifyGuestsCodesDeactivated(Chat $chat, array $deactivatedCodes): void
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0 || $deactivatedCodes === [])
		{
			return;
		}

		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $chatId)
			->where('USER.EXTERNAL_AUTH_ID', UserGuest::AUTH_ID)
			->where('USER.ACTIVE', true)
			->fetchAll()
		;

		$userIds = array_values(array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows));
		if ($userIds === [])
		{
			return;
		}

		(new UserLogout($userIds, $deactivatedCodes))->send();
	}
}
