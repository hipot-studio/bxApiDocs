<?php

namespace Bitrix\Im\V2\Chat\Comment;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * DTO-01: the number of replies a discussion holds, and the only place it is counted. Two paths
 * publish it - commentInfo on a history load and pull in a live session - and the plate of a root
 * post shows one after the other. Counted twice by two different rules, the number would change on
 * a reload without a single reply being written, which reads worse than a number that stands still.
 *
 * MESSAGE_COUNT of the discussion chat is not that number: it counts the greeting message, and being
 * a denormalized counter it only knows the write paths that maintain it. Hence the count over the
 * messages themselves, with the greeting told apart by its missing author, the same way LastMessages
 * tells it apart. A softly deleted reply stays a row with an author and is counted: the discussion
 * still shows it as a placeholder, so the plate agrees with what the discussion opens on.
 *
 * The greeting is subtracted rather than filtered out. b_im_message carries no (CHAT_ID, AUTHOR_ID)
 * index, so a single count with AUTHOR_ID > 0 has to leave IX_IM_MESS_4 for the row behind every
 * message it passes: the cost of a page follows the number of replies written into the discussions on
 * it, not the number of discussions. Both counts below stay inside an index instead - the total on
 * IX_IM_MESS_4, the authorless messages on the leading column of IX_IM_MESS_10 - and the page is back
 * to costing what it lists. Measured on a stand, a page of two hundred discussions holding 110k
 * messages went from 330k row reads to 1571, and from 73ms to 30ms. The price is a second branch to
 * render, about a millisecond per chunk, which a page of near-empty discussions pays for nothing.
 */
class ReplyCounter
{
	private const CHUNK_SIZE = 300;
	private const COUNT_ALIAS = 'CNT';

	/**
	 * One query per chunk keeps the cost off the number of discussions on the page, and counting inside
	 * an index keeps it off the number of replies they hold.
	 *
	 * @param int[] $chatIds ids of discussion chats
	 * @return array<int, int> discussion chat id => number of replies
	 */
	public static function getForChats(array $chatIds): array
	{
		$replyCounts = [];

		// deduplicated because the rows of a chunk are summed: the same chat twice would count twice
		foreach (array_chunk(array_unique($chatIds), self::CHUNK_SIZE) as $chunk)
		{
			// the greeting comes back as a negative count, so the rows of a discussion add up to its replies
			$rows = self::countQuery($chunk, 'COUNT(*)')
				->unionAll(self::countQuery($chunk, '-COUNT(*)')->where('AUTHOR_ID', 0))
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$chatId = (int)$row['CHAT_ID'];
				$replyCounts[$chatId] = ($replyCounts[$chatId] ?? 0) + (int)$row[self::COUNT_ALIAS];
			}
		}

		// a discussion holding nothing but its greeting stays out of the answer, as it always did
		return array_filter($replyCounts, static fn (int $replyCount): bool => $replyCount > 0);
	}

	public static function getForChat(int $chatId): int
	{
		if ($chatId <= 0)
		{
			return 0;
		}

		return self::getForChats([$chatId])[$chatId] ?? 0;
	}

	/**
	 * Equality on AUTHOR_ID for the authorless branch, never AUTHOR_ID <= 0: a range on the leading
	 * column costs IX_IM_MESS_10 and the branch falls back to reading rows.
	 *
	 * @param int[] $chatIds
	 */
	private static function countQuery(array $chatIds, string $count): Query
	{
		return MessageTable::query()
			->setSelect(['CHAT_ID', self::COUNT_ALIAS])
			->whereIn('CHAT_ID', $chatIds)
			->setGroup(['CHAT_ID'])
			->registerRuntimeField(self::COUNT_ALIAS, new ExpressionField(self::COUNT_ALIAS, $count))
		;
	}
}
