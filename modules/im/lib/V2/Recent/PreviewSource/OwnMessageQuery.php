<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Main\ORM\Fields\ExpressionField;

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

		return $value > 0 ? $value : null;
	}

	public function lastOwn(int $chatId): ?int
	{
		$row = MessageTable::query()
			->setSelect(['MAX_ID' => new ExpressionField('MAX_ID', 'MAX(%s)', ['ID'])])
			->where('CHAT_ID', $chatId)
			->fetch()
		;

		$value = $row ? (int)$row['MAX_ID'] : 0;

		return $value > 0 ? $value : null;
	}

	/**
	 * Batched {@see lastOwn} with the same semantics and filters.
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

		$result = [];
		foreach ($rows as $row)
		{
			$chatId = (int)$row['CHAT_ID'];
			$value = (int)$row['MAX_ID'];
			if ($chatId > 0 && $value > 0)
			{
				$result[$chatId] = $value;
			}
		}

		return $result;
	}
}
