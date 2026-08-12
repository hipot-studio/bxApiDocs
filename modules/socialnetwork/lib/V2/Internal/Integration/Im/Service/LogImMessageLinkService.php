<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Model\LogImMessageTable;

/**
 * Owner of the link access (service) contract.
 *
 * Bidirectional lookup and idempotent write of the message link
 * "feed post (LOG_ID) <-> system message in the project (collab) chat (IM_MESSAGE_ID)".
 *
 * A single post may be published to several project chats (cross-post to
 * several groups), so a link is unique per (LOG_ID, IM_CHAT_ID) pair, and one
 * LOG_ID may have several rows — one per chat.
 *
 * The service works only with the link table ({@see LogImMessageTable}) and
 * carries no side business logic. Consumers go exclusively through it.
 */
class LogImMessageLinkService
{
	/**
	 * Idempotent upsert of a link.
	 * Repeated calls with the same (logId, imChatId) pair do not duplicate the row.
	 */
	public function link(int $logId, int $imMessageId, int $imChatId, int $groupId): void
	{
		if ($logId <= 0 || $imMessageId <= 0 || $imChatId <= 0)
		{
			return;
		}

		// Conflict is detected by the composite PK (LOG_ID, IM_CHAT_ID);
		// IM_CHAT_ID is a key field, so it is absent from the update list.
		LogImMessageTable::merge(
			[
				'LOG_ID' => $logId,
				'IM_MESSAGE_ID' => $imMessageId,
				'IM_CHAT_ID' => $imChatId,
				'GROUP_ID' => $groupId,
				'DATE_CREATE' => new DateTime(),
			],
			[
				'IM_MESSAGE_ID' => $imMessageId,
				'GROUP_ID' => $groupId,
			],
		);
	}

	/**
	 * All links of a post by logId in a single SELECT.
	 *
	 * A post may be published to several project chats, so a list of
	 * IM_MESSAGE_ID + IM_CHAT_ID pairs is returned (one per chat). Each row is
	 * read atomically from one source — no paired queries and no desync window
	 * between them.
	 *
	 * @return list<LogImMessageLink>
	 *         empty array — no links (not collab / historical).
	 */
	public function getLinksByLogId(int $logId): array
	{
		if ($logId <= 0)
		{
			return [];
		}

		$rows = LogImMessageTable::getList([
			'select' => ['IM_MESSAGE_ID', 'IM_CHAT_ID'],
			'filter' => ['=LOG_ID' => $logId],
		]);

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = new LogImMessageLink(
				imMessageId: (int)$row['IM_MESSAGE_ID'],
				imChatId: (int)$row['IM_CHAT_ID'],
			);
		}

		return $result;
	}

	/**
	 * @param int[] $imMessageIds
	 * @return array<int, int> map [imMessageId => logId]
	 */
	public function getLinksByChatAndMessageIds(int $imChatId, array $imMessageIds): array
	{
		$imMessageIds = array_values(array_filter(array_map('intval', $imMessageIds), static fn (int $id): bool => $id > 0));

		if ($imChatId <= 0 || empty($imMessageIds))
		{
			return [];
		}

		$rows = LogImMessageTable::getList([
			'select' => ['LOG_ID', 'IM_MESSAGE_ID'],
			'filter' => [
				'=IM_CHAT_ID' => $imChatId,
				'@IM_MESSAGE_ID' => $imMessageIds,
			],
		]);

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['IM_MESSAGE_ID']] = (int)$row['LOG_ID'];
		}

		return $result;
	}

	/**
	 * Batch of linked-post logIds by chat (for read-all), keyset by PK LOG_ID.
	 *
	 * Read-all on a long-lived active collab project may cover hundreds/thousands
	 * of linked posts. To avoid an unbounded single query and a heavy background
	 * job, rows are fetched in batches via keyset pagination on the primary key
	 * LOG_ID (index-friendly, stable cursor).
	 *
	 * @param int $limit batch size (>0). No limit applied when <=0.
	 * @param int $lastLogId cursor: return only LOG_ID > $lastLogId (0 — from start).
	 * @param int $maxImMessageId upper bound: only links with IM_MESSAGE_ID <= this
	 *        (0 — no bound). Read-all snapshot: avoids marking posts cross-posted
	 *        into the chat after the read-all moment.
	 * @return int[] LOG_ID list ascending (to continue the cursor)
	 */
	public function getLogIdsByChatId(int $imChatId, int $limit = 0, int $lastLogId = 0, int $maxImMessageId = 0): array
	{
		if ($imChatId <= 0)
		{
			return [];
		}

		$filter = ['=IM_CHAT_ID' => $imChatId];
		if ($lastLogId > 0)
		{
			$filter['>LOG_ID'] = $lastLogId;
		}
		if ($maxImMessageId > 0)
		{
			// Snapshot upper bound (read-all race): exclude links whose system message
			// was created after the read-all moment. Residual filter on the
			// (IM_CHAT_ID, IM_MESSAGE_ID) composite; keyset stays on LOG_ID.
			$filter['<=IM_MESSAGE_ID'] = $maxImMessageId;
		}

		$parameters = [
			'select' => ['LOG_ID'],
			'filter' => $filter,
			'order' => ['LOG_ID' => 'ASC'],
		];
		if ($limit > 0)
		{
			$parameters['limit'] = $limit;
		}

		$rows = LogImMessageTable::getList($parameters);

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = (int)$row['LOG_ID'];
		}

		return $result;
	}

	/**
	 * Batch of linked system-message IM_MESSAGE_IDs by chat (for read-all
	 * feed->IM), keyset by the unique IM_MESSAGE_ID.
	 *
	 * Mirror of {@see self::getLogIdsByChatId}: that one returns a LOG_ID set
	 * (IM->feed direction), this one an IM_MESSAGE_ID set (feed->IM direction).
	 * Cursor on the UNIQUE index IM_MESSAGE_ID (index-friendly, stable), batched —
	 * no unbounded query on large projects.
	 *
	 * @param int $limit batch size (>0). No limit applied when <=0.
	 * @param int $lastImMessageId cursor: return only IM_MESSAGE_ID > $lastImMessageId (0 — from start).
	 * @return int[] IM_MESSAGE_ID list ascending (to continue the cursor).
	 */
	public function getImMessageIdsByChatId(int $imChatId, int $limit = 0, int $lastImMessageId = 0): array
	{
		if ($imChatId <= 0)
		{
			return [];
		}

		$filter = ['=IM_CHAT_ID' => $imChatId];
		if ($lastImMessageId > 0)
		{
			$filter['>IM_MESSAGE_ID'] = $lastImMessageId;
		}

		$parameters = [
			'select' => ['IM_MESSAGE_ID'],
			'filter' => $filter,
			'order' => ['IM_MESSAGE_ID' => 'ASC'],
		];
		if ($limit > 0)
		{
			$parameters['limit'] = $limit;
		}

		$rows = LogImMessageTable::getList($parameters);

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = (int)$row['IM_MESSAGE_ID'];
		}

		return $result;
	}

	/**
	 * Filters a logId set down to those that have at least one project-chat link.
	 *
	 * Used by the global read-all-chats sync (IM -> Feed): the candidate logIds come
	 * from the user's legacy feed badges and include ordinary/historical posts with no
	 * collab link. A single DISTINCT lookup drops those, so only linked (collab) posts
	 * are marked seen.
	 *
	 * @param int[] $logIds
	 * @return int[] subset of $logIds that have a link (empty in — empty out).
	 */
	public function filterLinkedLogIds(array $logIds): array
	{
		$logIds = array_values(array_unique(array_filter(
			array_map('intval', $logIds),
			static fn (int $id): bool => $id > 0,
		)));

		if (empty($logIds))
		{
			return [];
		}

		$rows = LogImMessageTable::getList([
			'select' => ['LOG_ID'],
			'filter' => ['@LOG_ID' => $logIds],
			'group' => ['LOG_ID'],
		]);

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = (int)$row['LOG_ID'];
		}

		return $result;
	}

	/**
	 * Deletes all links of a post (across all project chats: composite PK LOG_ID + IM_CHAT_ID).
	 */
	public function unlinkByLogId(int $logId): void
	{
		if ($logId <= 0)
		{
			return;
		}

		LogImMessageTable::deleteByFilter(['=LOG_ID' => $logId]);
	}

	public function unlinkByImMessageId(int $imMessageId): void
	{
		if ($imMessageId <= 0)
		{
			return;
		}

		// IM_MESSAGE_ID is globally unique (UX_SONET_LOG_IM_MSG): at most one row affected.
		LogImMessageTable::deleteByFilter(['=IM_MESSAGE_ID' => $imMessageId]);
	}

	/**
	 * Deletes all links of a project chat (orphan cleanup on chat deletion).
	 */
	public function unlinkByChatId(int $imChatId): void
	{
		if ($imChatId <= 0)
		{
			return;
		}

		LogImMessageTable::deleteByFilter(['=IM_CHAT_ID' => $imChatId]);
	}

	/**
	 * Deletes all links of a group/project (orphan cleanup on collab group deletion).
	 */
	public function unlinkByGroupId(int $groupId): void
	{
		if ($groupId <= 0)
		{
			return;
		}

		LogImMessageTable::deleteByFilter(['=GROUP_ID' => $groupId]);
	}
}
