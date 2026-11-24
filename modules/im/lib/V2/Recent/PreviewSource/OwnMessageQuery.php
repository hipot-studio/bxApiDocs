<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Asks about the messages belonging directly to a chat, excluding its thread/comment children which
 * live under a different CHAT_ID. "Own" means "belonging to the chat", not "authored by the user";
 * the last own message is the one the chat row itself names, the unread ones are counted per member.
 */
class OwnMessageQuery
{
	/**
	 * Does NOT check access: it only filters by IS_MUTED='N' and assumes $chatId is already an
	 * access-validated candidate for $userId. Do not reuse standalone for chats that have not passed
	 * the full access gate.
	 */
	public function freshestUnread(int $chatId, int $userId): ?int
	{
		$row = MessageUnreadTable::query()
			->setSelect(['MAX_MESSAGE_ID' => new ExpressionField('MAX_MESSAGE_ID', 'MAX(%s)', ['MESSAGE_ID'])])
			->where('USER_ID', $userId)
			->where('CHAT_ID', $chatId)
			->where('IS_MUTED', 'N')
			->fetch()
		;

		$value = $row ? (int)$row['MAX_MESSAGE_ID'] : 0;
		if ($value <= 0)
		{
			return null;
		}

		return $this->filterWithinHistoryBorder([$chatId => $value])[$chatId] ?? null;
	}

	/**
	 * Batched {@see freshestUnread} with the same semantics and filters, for the member fan-out: every
	 * member of a chunk asks about the one chat their ranking has picked, and the whole batch costs one
	 * aggregated statement rather than one per chat the members picked. The borders are read for the
	 * whole batch at once, the way {@see filterWithinHistoryBorder} reads them; a map keyed by member
	 * cannot go through that cut itself, because two members may ask about one chat with two answers.
	 *
	 * @param array<int,int> $chatIdByUser map userId => the chat this member asks about
	 * @return array<int,int> map userId => freshest unread MESSAGE_ID; members without one are absent
	 */
	public function freshestUnreadBatch(array $chatIdByUser): array
	{
		$chatByUser = [];
		foreach ($chatIdByUser as $userId => $chatId)
		{
			$userId = (int)$userId;
			$chatId = (int)$chatId;

			if ($userId > 0 && $chatId > 0)
			{
				$chatByUser[$userId] = $chatId;
			}
		}

		if (empty($chatByUser))
		{
			return [];
		}

		$freshestByUser = $this->fetchFreshestUnreadOfPairs($chatByUser);
		if (empty($freshestByUser))
		{
			return [];
		}

		$borders = $this->readHistoryBorders(array_values(array_unique($chatByUser)));

		$result = [];
		foreach ($freshestByUser as $userId => $messageId)
		{
			if ($messageId >= ($borders[$chatByUser[$userId]] ?? 0))
			{
				$result[$userId] = $messageId;
			}
		}

		return $result;
	}

	/**
	 * The freshest unread of every (member, chat) pair of a batch in one statement. The pairs are asked
	 * for as pairs - the members grouped by the chat they picked, one branch of the condition per chat -
	 * rather than as two independent lists: a chunk of two hundred members spread over the chats of a
	 * collab would otherwise put every member against every chat, and the answer would be read out of
	 * the cross product of the two. Every branch is a range of IX_IM_MESS_UNREAD_4 (CHAT_ID, USER_ID,
	 * MESSAGE_ID), which covers the grouping and the MAX as well, so the group needs no temporary table;
	 * IS_MUTED is checked on the rows the ranges return, the way the per-chat form checked it.
	 *
	 * The branches are bounded by the chats of one collab, and the members of a branch by the fan-out
	 * chunk ({@see \Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourceResolver}).
	 *
	 * @param array<int,int> $chatByUser map userId => the chat this member asks about
	 * @return array<int,int> map userId => freshest unread MESSAGE_ID
	 */
	private function fetchFreshestUnreadOfPairs(array $chatByUser): array
	{
		$usersByChat = [];
		foreach ($chatByUser as $userId => $chatId)
		{
			$usersByChat[$chatId][] = $userId;
		}

		$pairs = Query::filter()->logic('or');
		foreach ($usersByChat as $chatId => $userIds)
		{
			$pairs->where(
				Query::filter()
					->where('CHAT_ID', $chatId)
					->whereIn('USER_ID', $userIds)
			);
		}

		$rows = MessageUnreadTable::query()
			->setSelect([
				'CHAT_ID',
				'USER_ID',
				'MAX_MESSAGE_ID' => new ExpressionField('MAX_MESSAGE_ID', 'MAX(%s)', ['MESSAGE_ID']),
			])
			->where($pairs)
			->where('IS_MUTED', 'N')
			->setGroup(['CHAT_ID', 'USER_ID'])
			->fetchAll()
		;

		$freshestByUser = [];
		foreach ($rows as $row)
		{
			$messageId = (int)$row['MAX_MESSAGE_ID'];

			if ($messageId > 0)
			{
				$freshestByUser[(int)$row['USER_ID']] = $messageId;
			}
		}

		return $freshestByUser;
	}

	/**
	 * Single-chat {@see lastOwnBatch}: the batch is the only shape of the read, so the two cannot drift
	 * apart on a chat that has no border - or no row - to read.
	 */
	public function lastOwn(int $chatId): ?int
	{
		return $this->lastOwnBatch([$chatId])[$chatId] ?? null;
	}

	/**
	 * The last message of every chat of a batch, taken from the LAST_MESSAGE_ID the chat row carries
	 * rather than from an aggregate over the messages: the branch asking this walks every candidate of
	 * a collab, and a range of the PRIMARY does not grow with the messages the project has written,
	 * while a grouped MAX() over b_im_message does.
	 *
	 * @param int[] $chatIds
	 * @return array<int,int> map chatId => last own MESSAGE_ID; chats without an own message are absent
	 */
	public function lastOwnBatch(array $chatIds): array
	{
		$chatIds = array_values(array_unique(array_map('intval', $chatIds)));
		if (empty($chatIds))
		{
			return [];
		}

		$rows = ChatTable::query()
			->setSelect(['ID', 'LAST_MESSAGE_ID'])
			->whereIn('ID', $chatIds)
			->where('LAST_MESSAGE_ID', '>', 0)
			->fetchAll()
		;

		$lastOwnByChat = [];
		foreach ($rows as $row)
		{
			$lastOwnByChat[(int)$row['ID']] = (int)$row['LAST_MESSAGE_ID'];
		}

		return $this->filterWithinHistoryBorder($lastOwnByChat);
	}

	/**
	 * The entries whose message is still readable. The message handed in is the newest one its chat
	 * has, so a message behind the history border means everything the chat holds is behind it and
	 * there is nothing left to preview. Without this the resolver would pick a message cleared for
	 * everyone, which stays physically in the table until the scheduled cleanup reaches it.
	 *
	 * A chat with no border row keeps whatever the caller found, the way a chat that does not exist
	 * does: neither has a border to stand behind.
	 *
	 * @param array<int,int> $messageIdByChat map chatId => the newest MESSAGE_ID found for the chat
	 * @return array<int,int> the same map without the chats standing entirely behind their border
	 */
	public function filterWithinHistoryBorder(array $messageIdByChat): array
	{
		if (empty($messageIdByChat))
		{
			return [];
		}

		$borders = $this->readHistoryBorders(array_keys($messageIdByChat));

		$result = [];
		foreach ($messageIdByChat as $chatId => $messageId)
		{
			if ($messageId >= ($borders[$chatId] ?? 0))
			{
				$result[$chatId] = $messageId;
			}
		}

		return $result;
	}

	/**
	 * The borders of a whole batch in one range over IX_B_IM_CHAT_PARAM_1 (CHAT_ID, PARAM_NAME). Read
	 * from the table rather than from the chats for the reason
	 * {@see \Bitrix\Im\V2\Chat\Cleanup\ChatHistoryCleanupAgent::readHistoryBorder()} gives: a border
	 * written by another hit has to be seen.
	 *
	 * @param int[] $chatIds
	 * @return array<int,int> map chatId => HISTORY_START_ID; chats without a border are absent
	 */
	private function readHistoryBorders(array $chatIds): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$rows = ChatParamTable::query()
			->setSelect(['CHAT_ID', 'PARAM_VALUE'])
			->whereIn('CHAT_ID', $chatIds)
			->where('PARAM_NAME', Params::HISTORY_START_ID)
			->fetchAll()
		;

		$borders = [];
		foreach ($rows as $row)
		{
			$chatId = (int)$row['CHAT_ID'];
			// The highest of them: a chat cleaned by an earlier version of this path may carry more than one row.
			$borders[$chatId] = max($borders[$chatId] ?? 0, (int)$row['PARAM_VALUE']);
		}

		return $borders;
	}
}
