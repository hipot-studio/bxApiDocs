<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Integration\Notifications\NotificationService;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Guest\Pull\UserLogout;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\SharingLink\SharingLinkMemberService;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\Dto\CreateDto;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\GuestInviteService;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAuthActionTable;
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
		$revokedLinkId = $existingLink?->getId();

		$generateResult = $factory->generateLink(
			dto: $this->buildIndividualLinkDto($chat, $authorId),
			forceGenerate: true,
		);

		if ($generateResult->isSuccess())
		{
			if ($revokedLinkId !== null)
			{
				$this->kickGuestsByRevokedLinks($chat, [$revokedLinkId]);
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

		$revokedLinkId = $existingLink->getId();
		$revokeResult = $existingLink->revoke();

		if ($revokeResult->isSuccess())
		{
			if ($revokedLinkId !== null)
			{
				$this->kickGuestsByRevokedLinks($chat, [$revokedLinkId]);
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
			->setSelect(['ID', 'AUTHOR_ID'])
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
			}
		}
		if ($ineligibleLinkIds === [])
		{
			return;
		}

		SharingLinkTable::updateMulti($ineligibleLinkIds, ['IS_REVOKED' => 'Y'], true);

		$this->kickGuestsByRevokedLinks($chat, $ineligibleLinkIds);
	}

	public function onChatMemberDeleted(Chat $chat, int $userId): void
	{
		$chatId = $chat->getId() ?? 0;
		if ($chatId <= 0 || $userId <= 0)
		{
			return;
		}

		$links = SharingLinkTable::query()
			->setSelect(['ID'])
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
		foreach ($links as $link)
		{
			$ids[] = $link->getId();
		}

		SharingLinkTable::updateMulti($ids, ['IS_REVOKED' => 'Y'], true);

		$this->kickGuestsByRevokedLinks($chat, $ids);
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
	 * Kill-switch: push userLogout to all active guests, sever their sessions, pull channels
	 * and push tokens, defer the bulk revoke to a one-shot agent. Security holds meanwhile via
	 * the lazy feature check; the guests themselves are deactivated later by the periodic
	 * {@see CleanupService} sweep.
	 */
	private function onFeatureDisabled(): void
	{
		$this->logoutAllActiveGuests();
		self::scheduleRevokeAllGuestLinksAgent();
	}

	/**
	 * Chunked one-shot agent; the $lastSeenId cursor lives in the agent name (keyset, no re-scan).
	 * Returns its cursor-bumped name to reschedule, '' to unregister; bails out if the feature is back on.
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
	 * @return int|null next cursor when the batch was full; null when done
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

	/**
	 * Keyset batches: the portal-wide guest list is unbounded, one giant pull event on the
	 * option Save-handler hit is not.
	 */
	private function logoutAllActiveGuests(): void
	{
		$lastSeenId = 0;
		do
		{
			$userIds = [];
			$result = UserTable::query()
				->setSelect(['ID'])
				->where('EXTERNAL_AUTH_ID', UserGuest::AUTH_ID)
				->where('ACTIVE', 'Y')
				->where('ID', '>', $lastSeenId)
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::REVOKE_BATCH_SIZE)
				->exec()
			;
			while ($row = $result->fetch())
			{
				$userId = (int)$row['ID'];
				$userIds[] = $userId;
				$lastSeenId = $userId;
			}
			if ($userIds === [])
			{
				return;
			}

			(new UserLogout($userIds))->send();
			$this->severGuestSessions($userIds);
			$this->dropGuestPullChannels($userIds);
			$this->dropGuestPushTokens($userIds);
		}
		while (count($userIds) === self::REVOKE_BATCH_SIZE);
	}

	/**
	 * The guest's PHP session survives the kill-switch until the {@see CleanupService} sweep
	 * deactivates the user, and mobile-native paths ('/mobile/' in the im_guest_mobile scope)
	 * let a live app re-register its push token with nothing but IsAuthorized(). A logout auth
	 * action kills the session in the prolog of the guest's very next hit — before any token
	 * re-registration action runs.
	 *
	 * Rows mirror {@see UserAuthActionTable::addLogoutAction()}, batched into one multi-INSERT;
	 * addMulti() cleans the entity cache, so cached CheckAuthActions reads see the action at once.
	 *
	 * @param list<int> $userIds
	 */
	private function severGuestSessions(array $userIds): void
	{
		$actionDate = new DateTime();
		$rows = [];
		foreach ($userIds as $userId)
		{
			$rows[] = [
				'USER_ID' => $userId,
				'PRIORITY' => UserAuthActionTable::PRIORITY_HIGH,
				'ACTION' => UserAuthActionTable::ACTION_LOGOUT,
				'ACTION_DATE' => $actionDate,
			];
		}

		UserAuthActionTable::addMulti($rows, true);
	}

	/**
	 * Relations survive the kill-switch, so an open channel would keep receiving fan-out until
	 * TTL (~12h) — terminating the channels cuts non-cooperative clients too. Runs strictly
	 * AFTER the userLogout flush: recipients' channels are resolved at send time.
	 *
	 * @param list<int> $userIds
	 */
	private function dropGuestPullChannels(array $userIds): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		\Bitrix\Pull\Event::send();
		\CPullChannel::DeleteByUsers($userIds);
	}

	/**
	 * Native pushes resolve recipients from surviving relations and devices from b_pull_push
	 * at send time, ignoring pull channels entirely; guests stay ACTIVE until the periodic
	 * {@see CleanupService} sweep — so the tokens must go now, or "silent" mobile guests keep
	 * receiving pushes until an app restart.
	 *
	 * @param list<int> $userIds
	 */
	private function dropGuestPushTokens(array $userIds): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		\Bitrix\Pull\Model\PushTable::deleteByUsers($userIds);
	}

	/**
	 * Kicks exactly the guests that joined via the revoked links; a guest with another live link
	 * to this chat keeps the seat. Full logout happens inside deleteUser only when no access remains.
	 *
	 * @param list<int> $revokedLinkIds
	 */
	private function kickGuestsByRevokedLinks(Chat $chat, array $revokedLinkIds): void
	{
		$chatId = $chat->getId() ?? 0;
		$revokedLinkIds = array_values(array_filter(
			array_map(static fn ($id): int => (int)$id, $revokedLinkIds),
			static fn (int $id): bool => $id > 0,
		));
		if ($chatId <= 0 || $revokedLinkIds === [])
		{
			return;
		}

		$memberService = SharingLinkMemberService::getInstance();
		$userIds = $memberService->getUserIdsByLinkIds($revokedLinkIds);
		$usersToKeep = $memberService->getUsersWithAnotherActiveLinkInChat($userIds, $chatId, $revokedLinkIds);

		foreach ($userIds as $userId)
		{
			if (isset($usersToKeep[$userId]))
			{
				continue;
			}

			$deleteResult = $chat->deleteUser($userId, new DeleteUserConfig(withMessage: false, withNotification: false));
			if (!$deleteResult->isSuccess())
			{
				// Failed kick leaves a member on a revoked link; access dies lazily — log it.
				(new LoggerFactory())->createById('im.Guest.Kick')?->error(
					'Failed to kick guest by revoked link',
					[
						'chatId' => $chatId,
						'userId' => $userId,
						'errors' => $deleteResult->getErrorMessages(),
					],
				);
			}
		}

		$memberService->deleteByLinkIds($revokedLinkIds);
	}
}
