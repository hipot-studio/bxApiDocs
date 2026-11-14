<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Queries messages belonging directly to a chat (CHAT_ID == chatId), excluding its thread/comment
 * children which live under a different CHAT_ID. "Own" means "belonging to the chat", not "authored
 * by the user".
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

		return $this->skipBehindHistoryBorder($chatId, $value > 0 ? $value : null);
	}

	/**
	 * Batched {@see freshestUnread} with the same semantics and filters, for the member fan-out: every
	 * member of a chunk asks about the one chat their ranking has picked, and the whole batch costs one
	 * aggregated statement rather than one per chat the members picked. The borders are read for the
	 * whole batch at once, the way {@see lastOwnBatch} reads them.
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

	public function lastOwn(int $chatId): ?int
	{
		$row = MessageTable::query()
			->setSelect(['MAX_ID' => new ExpressionField('MAX_ID', 'MAX(%s)', ['ID'])])
			->where('CHAT_ID', $chatId)
			->fetch()
		;

		$value = $row ? (int)$row['MAX_ID'] : 0;

		return $this->skipBehindHistoryBorder($chatId, $value > 0 ? $value : null);
	}

	/**
	 * Batched {@see lastOwn} with the same semantics and filters. The borders are read for the whole
	 * batch at once rather than per chat: a chat instance carries no parameters of its own, so the
	 * per-chat form would load a chat and a parameter set for every candidate on top of the one
	 * aggregated statement.
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

		$rows = MessageTable::query()
			->setSelect([
				'CHAT_ID',
				'MAX_ID' => new ExpressionField('MAX_ID', 'MAX(%s)', ['ID']),
			])
			->whereIn('CHAT_ID', $chatIds)
			->setGroup(['CHAT_ID'])
			->fetchAll()
		;

		$lastOwnByChat = [];
		foreach ($rows as $row)
		{
			$chatId = (int)$row['CHAT_ID'];
			$messageId = (int)$row['MAX_ID'];

			if ($chatId > 0 && $messageId > 0)
			{
				$lastOwnByChat[$chatId] = $messageId;
			}
		}

		$borders = $this->readHistoryBorders(array_keys($lastOwnByChat));

		$result = [];
		foreach ($lastOwnByChat as $chatId => $messageId)
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
	 * written by another hit has to be seen. A chat with no border row keeps whatever the query found,
	 * the way a chat that does not exist does.
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

	/**
	 * MAX() is the newest message the chat has, so a MAX behind the history border means everything the
	 * chat holds is behind it and there is nothing left to preview. Without this the resolver would pick
	 * a message cleared for everyone, which stays physically in the table until the scheduled cleanup
	 * reaches it. A chat that does not exist keeps whatever the query found: it has no border to read.
	 */
	private function skipBehindHistoryBorder(int $chatId, ?int $messageId): ?int
	{
		if ($messageId === null || $messageId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);

		if ($chat instanceof Chat\NullChat)
		{
			return $messageId;
		}

		return $chat->isMessageBehindHistoryBorder($messageId) ? null : $messageId;
	}
}
