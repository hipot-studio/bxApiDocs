<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Event\RecentUpdate;
use Bitrix\Im\V2\Pull\Event\RecentUpdateMeta;
use Bitrix\Im\V2\Recent\Internal\RecentItemCache;

/**
 * Cross-chat invalidation of the materialized preview-source pointer.
 *
 * The pointer of a collab recent row references a message in another (child) source chat, so it must
 * be recomputed on source-side events (mute/delete/leave/reparent) that could leave it stale. Methods
 * are no-ops when the feature flag is off. These are cold paths, so the point-wise recompute here
 * intentionally avoids a mass cache wipe and drops only the touched user/chat cache rows.
 */
readonly class CollabPreviewSourcePointerService
{
	/** Max USER_ID ids per batched pointer UPDATE (bounds the locks held by one statement). */
	private const WRITE_CHUNK_SIZE = 500;

	public function __construct(
		private CollabPreviewSourceResolver $resolver,
		private RecentItemCache $recentItemCache,
	) {}

	public function recomputeForUser(int $collabChatId, int $userId): void
	{
		if (!Features::isCollabPreviewSourceEnabled() || $collabChatId <= 0 || $userId <= 0)
		{
			return;
		}

		$source = $this->resolver->resolve($collabChatId, $userId);
		$this->writePointer($collabChatId, [$userId => $source]);
	}

	/**
	 * Recompute the parent collab row pointer after a source chat left the user's recent (hideUser/leave).
	 * No-op unless the chat is a direct, non-thread child of a collab.
	 */
	public function recomputeForRemovedSourceChat(Chat $chat, int $userId): void
	{
		if (!Features::isCollabPreviewSourceEnabled() || $userId <= 0)
		{
			return;
		}

		if ($chat instanceof Chat\NullChat || $chat->getType() === Chat::IM_TYPE_COMMENT)
		{
			return;
		}

		$parent = $chat->getParentChat();
		if (!$parent instanceof Chat\CollabChat)
		{
			return;
		}

		$collabChatId = (int)$parent->getChatId();

		// Skip the resolver pass and the pull when the removed chat is not the row's current preview source.
		$row = RecentTable::query()
			->setSelect(['PREVIEW_SOURCE_CID', 'DATE_LAST_ACTIVITY'])
			->where('USER_ID', $userId)
			->where('ITEM_CID', $collabChatId)
			->fetch()
		;
		if (!$row || (int)($row['PREVIEW_SOURCE_CID'] ?? 0) !== (int)$chat->getChatId())
		{
			return;
		}

		$this->recomputeForUser($collabChatId, $userId);

		// Push the refreshed per-user preview so the row updates live; the same lastActivity keeps its sort order.
		(new RecentUpdate($parent, [$userId], $row['DATE_LAST_ACTIVITY'] ?? null))->send();
	}

	/**
	 * Read-path recompute: recomputes the reading user's collab row and returns the collab chat id whose
	 * pointer was refreshed (so the caller can push the preview), or null when there is no recompute.
	 *
	 * Known limitation: a read inside a thread (two levels under the collab) is not recomputed in realtime,
	 * matching the one-level-up resolution used across the feature; it self-corrects on the next read.
	 */
	public function recomputeForSourceChatRead(int $sourceChatId, int $userId): ?int
	{
		if (!Features::isCollabPreviewSourceEnabled() || $sourceChatId <= 0 || $userId <= 0)
		{
			return null;
		}

		$collabChatId = $this->resolveCollabAncestorId($sourceChatId);
		if ($collabChatId === null)
		{
			return null;
		}

		$row = RecentTable::query()
			->setSelect(['PREVIEW_SOURCE_CID', 'PREVIEW_SOURCE_MID'])
			->where('USER_ID', $userId)
			->where('ITEM_CID', $collabChatId)
			->fetch()
		;
		if (!$row)
		{
			// No own recent row in the collab: there is nothing to rewrite, and pushing a collab
			// recentUpdate would leak the project into the user's chat list.
			return null;
		}

		$source = $this->resolver->resolve($collabChatId, $userId);
		[$sourceChatColumn, $messageColumn] = $this->pointerColumns($source);
		if (
			(int)($row['PREVIEW_SOURCE_CID'] ?? 0) === $sourceChatColumn
			&& (int)($row['PREVIEW_SOURCE_MID'] ?? 0) === $messageColumn
		)
		{
			// A partial read keeps the same freshest unread message as the pointer. Skip the write and
			// the push: a self-addressed recentUpdate here would deliver the still-unread tail message
			// to the reading client as a bare preview payload.
			return null;
		}

		$this->writePointer($collabChatId, [$userId => $source]);

		return $collabChatId;
	}

	/**
	 * Concurrent recomputes are idempotent per row: every write is a full pointer pair, so the last
	 * write wins and the next recompute self-corrects a winner resolved from a stale read.
	 *
	 * @param int[] $userIds
	 */
	private function recomputeForUsers(int $collabChatId, array $userIds): void
	{
		if (!Features::isCollabPreviewSourceEnabled() || $collabChatId <= 0)
		{
			return;
		}

		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id) => $id > 0)));
		if (empty($userIds))
		{
			return;
		}

		// One multi-user freshness statement per member chunk instead of resolve() per member.
		$this->writePointer($collabChatId, $this->resolver->resolveForMembers($collabChatId, $userIds));
	}

	public function recomputeForCollabMembers(int $collabChatId): void
	{
		if (!Features::isCollabPreviewSourceEnabled() || $collabChatId <= 0)
		{
			return;
		}

		$this->recomputeForUsers($collabChatId, $this->getCollabMemberIds($collabChatId));
	}

	/**
	 * Recompute every collab member's preview pointer after a child chat was detached from the project.
	 * The detached chat (its PARENT_ID is now 0) is no longer a resolver candidate, so a pointer that
	 * still references it falls back to an earlier message / the collab's own last message.
	 *
	 * Detach recomputes AFTER UpdateService already emitted its recent pull (which carried the stale
	 * pointer), so an info-only RecentUpdateMeta is pushed here to refresh the live preview in place
	 * without moving the row.
	 */
	public function recomputeForDetachedChild(Chat\CollabChat $collab): void
	{
		if (!Features::isCollabPreviewSourceEnabled())
		{
			return;
		}

		$collabChatId = (int)$collab->getChatId();
		if ($collabChatId <= 0)
		{
			return;
		}

		$memberIds = $this->getCollabMemberIds($collabChatId);
		if (empty($memberIds))
		{
			return;
		}

		$this->recomputeForUsers($collabChatId, $memberIds);

		(new RecentUpdateMeta($collab, $memberIds))->send();
	}

	/**
	 * Recomputes only the collab rows whose stored PREVIEW_SOURCE_MID matches one of the deleted ids
	 * (point-wise, not a mass wipe). A thread message id never appears as a stored pointer, so passing
	 * one is a harmless no-op.
	 *
	 * @param int[] $deletedMessageIds
	 */
	public function recomputeForDeletedMessages(int $sourceChatId, array $deletedMessageIds): void
	{
		if (!Features::isCollabPreviewSourceEnabled() || $sourceChatId <= 0)
		{
			return;
		}

		$deletedMessageIds = array_values(
			array_unique(array_filter(array_map('intval', $deletedMessageIds), static fn (int $id) => $id > 0))
		);
		if (empty($deletedMessageIds))
		{
			return;
		}

		$collabChatId = $this->resolveCollabAncestorId($sourceChatId);
		if ($collabChatId === null)
		{
			return;
		}

		$rows = RecentTable::query()
			->setSelect(['USER_ID'])
			->where('ITEM_CID', $collabChatId)
			->whereIn('PREVIEW_SOURCE_MID', $deletedMessageIds)
			->fetchAll()
		;
		if (empty($rows))
		{
			return;
		}

		$userIds = array_map('intval', array_column($rows, 'USER_ID'));
		$this->recomputeForUsers($collabChatId, $userIds);

		// recomputeForUsers only writes the DB pointer; the delete flow emits no collab pull (messageDeleteV2
		// targets the source child row), so push an info-only RecentUpdateMeta to refresh the live preview in
		// place. The recomputed pointer is already persisted above, so the event reads the fresh source.
		$collab = Chat::getInstance($collabChatId);
		if ($collab instanceof Chat\CollabChat)
		{
			(new RecentUpdateMeta($collab, $userIds))->send();
		}
	}

	/**
	 * Resolves the collab ancestor one level up: the chat itself if it is a collab, otherwise its direct
	 * parent if that is a collab, else null. A chat two levels under a collab (thread) is deliberately not
	 * resolved, since only one level up is walked.
	 */
	private function resolveCollabAncestorId(int $chatId): ?int
	{
		$chat = Chat::getInstance($chatId);
		if ($chat instanceof Chat\NullChat)
		{
			return null;
		}

		if ($chat instanceof Chat\CollabChat)
		{
			return (int)$chat->getChatId();
		}

		$parent = $chat->getParentChat();
		if ($parent instanceof Chat\CollabChat)
		{
			return (int)$parent->getChatId();
		}

		return null;
	}

	/**
	 * @param array<int,PreviewSource> $sources keyed by userId
	 */
	private function writePointer(int $collabChatId, array $sources): void
	{
		// A fan-out mostly converges on a handful of distinct pointer values - group the members by
		// the materialized pair and update each group with one statement instead of one per member.
		$groups = [];
		foreach ($sources as $userId => $source)
		{
			$userId = (int)$userId;
			if ($userId <= 0)
			{
				continue;
			}

			[$sourceChatColumn, $messageColumn] = $this->pointerColumns($source);
			$key = "{$sourceChatColumn}:{$messageColumn}";
			$groups[$key]['fields'] ??= [
				'PREVIEW_SOURCE_CID' => $sourceChatColumn,
				'PREVIEW_SOURCE_MID' => $messageColumn,
			];
			$groups[$key]['userIds'][] = $userId;
		}

		foreach ($groups as $group)
		{
			// Cap the IN list so one huge single-pointer collab does not hold locks on all its recent
			// rows in a single statement (mirrors the resolver's fan-out chunk discipline).
			foreach (array_chunk($group['userIds'], self::WRITE_CHUNK_SIZE) as $chunk)
			{
				RecentTable::updateByFilter(
					[
						'@USER_ID' => $chunk,
						'=ITEM_CID' => $collabChatId,
					],
					$group['fields'],
				);

				foreach ($chunk as $userId)
				{
					$this->recentItemCache->remove($userId, $collabChatId);
				}
			}
		}
	}

	/**
	 * isMainChat materializes as 0/0, meaning the source is the row itself.
	 *
	 * @return array{int,int} [PREVIEW_SOURCE_CID, PREVIEW_SOURCE_MID]
	 */
	private function pointerColumns(PreviewSource $source): array
	{
		return $source->isMainChat ? [0, 0] : [$source->sourceChatId, $source->messageId];
	}

	/**
	 * @return int[]
	 */
	private function getCollabMemberIds(int $collabChatId): array
	{
		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $collabChatId)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
	}
}
