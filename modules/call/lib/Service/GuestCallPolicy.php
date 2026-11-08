<?php

declare(strict_types=1);

namespace Bitrix\Call\Service;

use Bitrix\Call\Integration\EntityType;
use Bitrix\Call\Settings;
use Bitrix\Main\Loader;

/**
 * What a guest - a user without a portal account, reaching the portal by a chat link - may do
 * around starting a call.
 *
 * A guest answers calls and takes part in them regardless of any option here. Two things are
 * governed: starting a call at all (a portal-level switch), and who a guest may ring - the people
 * of their own chat and nobody else.
 */
class GuestCallPolicy
{
	private const MEMBERSHIP_LOOKUP_CHUNK = 300;

	private CallLogService $callLogService;

	public function __construct(?CallLogService $callLogService = null)
	{
		$this->callLogService = $callLogService ?? new CallLogService();
	}

	/**
	 * Whether the user is a guest.
	 *
	 * Without im the question has no answer, and every rule of this class then keeps its closed default -
	 * the same one areChatMembers() keeps. CallLogService answers the very same question with false, which
	 * is its own semantics: a journal it cannot narrow is left unnarrowed rather than emptied.
	 */
	public function isGuest(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (!Loader::includeModule('im'))
		{
			return true;
		}

		return $this->callLogService->isGuestUser($userId);
	}

	/**
	 * Whether the user may start a call at all. Everyone but a guest always may; a guest may
	 * while the portal keeps guest outgoing calls switched on.
	 */
	public function canStartCall(int $userId): bool
	{
		if (Settings::isGuestOutgoingCallEnabled())
		{
			return true;
		}

		return !$this->isGuest($userId);
	}

	/**
	 * Whether the user may ring these people into the call of that chat.
	 *
	 * The invite of a start names exactly the members of the chat the call belongs to, while the
	 * participant selector of a running call reaches the whole portal - so membership is what tells
	 * the two apart. A guest is held to the first; everyone else passes.
	 *
	 * @param int[] $userIds
	 */
	public function canInviteUsers(int $userId, int $chatId, array $userIds): bool
	{
		if (!$this->isGuest($userId))
		{
			return true;
		}

		$userIds = array_filter(array_map('intval', $userIds));
		if (empty($userIds))
		{
			// A start in the JWT scheme names nobody: the server rings the chat members itself, so there
			// is no list here to check.
			return true;
		}

		// The caller is checked alongside the invitees rather than left to the calling code: the member
		// list is loaded anyway.
		return $this->areChatMembers($chatId, array_merge($userIds, [$userId]));
	}

	/**
	 * The same question as canInviteUsers(), asked of the relations as they stand now.
	 *
	 * im keeps the relations of a chat for the rest of the request once read, so an answer given before
	 * the wait for the invite lock would be repeated as is after it - even if an invitee left the chat
	 * meanwhile. The invite asks again under the lock with the kept relations dropped.
	 *
	 * @param int[] $userIds
	 */
	public function canInviteUsersNow(int $userId, int $chatId, array $userIds): bool
	{
		if ($this->isGuest($userId) && $chatId > 0 && Loader::includeModule('im'))
		{
			\Bitrix\Im\V2\Relation\ChatRelations::getInstance($chatId)->cleanCache();
		}

		return $this->canInviteUsers($userId, $chatId, $userIds);
	}

	/**
	 * Whether the user may start a call in that entity.
	 *
	 * The entity id of a start comes in two shapes: "chatNNN" - a chat, and a bare user id - a 1-1
	 * dialogue, which the call entity resolves by creating the private chat of the two if there is
	 * none yet. The second shape would let a guest ring any employee of the portal and leave a fresh
	 * private chat behind, so a guest is held to the first shape and to the chats they belong to.
	 * Everyone else passes.
	 */
	public function canStartCallInEntity(int $userId, string $entityType, string $entityId): bool
	{
		if (!$this->isGuest($userId))
		{
			return true;
		}

		if ($entityType !== EntityType::CHAT)
		{
			return false;
		}

		return $this->canStartCallInChat($userId, self::extractChatId($entityId));
	}

	/**
	 * Whether the user may start a call in that chat.
	 *
	 * The JWT scheme comes back to the portal by a webhook that names the chat of the media room and no
	 * entity id at all, so the shape check above has nothing to read there and the chat is the only thing
	 * left to hold a guest to. A chat id of zero, whatever produced it, is a chat nobody is a member of.
	 */
	public function canStartCallInChat(int $userId, int $chatId): bool
	{
		if (!$this->isGuest($userId))
		{
			return true;
		}

		return $this->areChatMembers($chatId, [$userId]);
	}

	/**
	 * The chat is asked about the ids at hand rather than read out whole: the chat id arrives in the
	 * request, and reading every relation of it would let a stranger have a chat of any size loaded
	 * before the check refuses them. The list of ids is untrusted too, hence the chunks - and the
	 * first chunk holding somebody outside the chat already answers the question.
	 *
	 * A hidden relation is not counted, which is what getAllMemberIds() did here before.
	 *
	 * @param int[] $userIds
	 */
	private function areChatMembers(int $chatId, array $userIds): bool
	{
		if ($chatId <= 0 || !Loader::includeModule('im'))
		{
			return false;
		}

		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		if ($userIds === [])
		{
			return false;
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
		if ($chat->getId() !== $chatId)
		{
			return false;
		}

		foreach (array_chunk($userIds, self::MEMBERSHIP_LOOKUP_CHUNK) as $chunk)
		{
			$memberIds = [];
			foreach ($chat->getRelationsByUserIds($chunk) as $relation)
			{
				if (!$relation->isHidden())
				{
					$memberIds[] = (int)$relation->getUserId();
				}
			}

			if (array_diff($chunk, $memberIds) !== [])
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Only the plain "chatNNN" shape is read here, and read without asking im to resolve it: the
	 * resolver of a dialogue id creates the chat behind the other shapes, which is the very side
	 * effect this check exists to keep away from a guest.
	 */
	private static function extractChatId(string $entityId): int
	{
		if (preg_match('/^chat(\d+)$/i', $entityId, $matches) !== 1)
		{
			return 0;
		}

		return (int)$matches[1];
	}
}
