<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Comment;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Common\RowsToMapHelper;
use Bitrix\Im\V2\Message\LastMessages;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Im\V2\TariffLimit\Limit;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * API-01: the table of contents of the discussions of a chat. It returns every thread of the parent
 * and not the subscriptions of the reader alone (AC-022), so the reader sees what is being discussed
 * and not only what they already follow.
 *
 * Every piece of data a page needs is warmed in batches: the discussions in one query, their reply
 * counts, their last authors and the dates of their last replies in one query each. A query per chat
 * is the risk this list exists to avoid - a chat can hold hundreds of threads.
 */
class CommentListProvider
{
	use ContextCustomer;

	public function __construct(
		private readonly UserCountersCollector $countersCollector,
	) {}

	public function getPage(Chat $parentChat, int $limit, int $offset): ThreadListPage
	{
		$parentChatId = (int)$parentChat->getId();
		$userId = $this->getContext()->getUserId();
		$unreadCounts = $this->getUnreadCounts($parentChatId, $userId);

		$candidates = $this
			->buildQuery($parentChatId, $userId, $parentChat->getStartId($userId), array_keys($unreadCounts))
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll()
		;

		$rows = $this->dropThreadsBeyondHistoryLimit($this->dropEmptyThreads($candidates));
		$chatIds = array_map(static fn (array $row): int => (int)$row['ID'], $rows);
		$lastMessages = new LastMessages($chatIds);
		$lastMessageDates = $this->getLastMessageDates($rows);

		$items = [];

		foreach ($rows as $row)
		{
			$chatId = (int)$row['ID'];
			$items[] = new ThreadListItem(
				chatId: $chatId,
				parentMessageId: (int)$row['PARENT_MID'],
				messageCount: (int)$row['MESSAGE_COUNT'],
				replyCount: (int)$row['REPLY_COUNT'],
				unreadCount: $unreadCounts[$chatId] ?? 0,
				lastMessageDate: $lastMessageDates[$chatId],
				lastUserIds: $lastMessages->getUsersByChatId($chatId),
				isUserSubscribed: ($row['NOTIFY_BLOCK'] ?? 'Y') === 'N',
			);
		}

		// the next page starts where this window ended and not where its surviving cells did: the rows
		// the filters above dropped have spent their place in the ordered sequence all the same
		return new ThreadListPage($items, count($candidates) >= $limit, $offset + count($candidates));
	}

	/**
	 * The type condition is not decoration: by PARENT_ID alone the nested chats of a collab and of a
	 * project - its channels and its task chats - would land in the list of threads of their root.
	 *
	 * MESSAGE_COUNT is deliberately absent from the conditions. It reads like a cheap prefilter for the
	 * threads nobody answered in, but the counter drifts downwards - complete deletion subtracts the
	 * messages it removes, and im/lib/update/chatmessagecount.php exists to repair the drift - so a
	 * thread holding live replies can sit at 1 and silently lose its row. Emptiness is decided in one
	 * place, by the reply counts of the fetched window (AC-022).
	 *
	 * @param int[] $unreadChatIds
	 */
	private function buildQuery(int $parentChatId, int $userId, int $startId, array $unreadChatIds): Query
	{
		// A positive PARENT_MID is the structural marker of a thread, and the start id of the reader
		// raises that bound: a thread of a post they may not read stays out, because the post itself
		// rides in the popup block of the answer.
		$query = ChatTable::query()
			->setSelect(['ID', 'PARENT_MID', 'MESSAGE_COUNT', 'LAST_MESSAGE_ID', 'DATE_CREATE'])
			->where('PARENT_ID', $parentChatId)
			->where('TYPE', Chat::IM_TYPE_COMMENT)
			->where('PARENT_MID', '>=', max($startId, 1))
			->addSelect('RELATION.NOTIFY_BLOCK', 'NOTIFY_BLOCK')
			->withRelation($userId)
		;

		// AC-023: the discussions waiting for the reader come first, the rest follow by the date of
		// their last reply. LAST_MESSAGE_ID stands for that date in the order because message ids grow
		// with time and, being unique, give the sequence the total order an offset needs.
		if (empty($unreadChatIds))
		{
			return $query->setOrder(['LAST_MESSAGE_ID' => 'DESC']);
		}

		$rank = new ExpressionField(
			'UNREAD_RANK',
			'CASE WHEN %s IN (' . implode(',', array_map('intval', $unreadChatIds)) . ') THEN 0 ELSE 1 END',
			['ID'],
		);
		$rank->configureValueType(IntegerField::class);
		$query->registerRuntimeField($rank);

		return $query->setOrder(['UNREAD_RANK' => 'ASC', 'LAST_MESSAGE_ID' => 'DESC']);
	}

	/**
	 * AC-022: a thread nobody has answered in is not part of the table of contents. The predicate is
	 * the actual aggregate of replies and never MESSAGE_COUNT, which keeps counting the replies that
	 * were deleted.
	 *
	 * @param array<int, array> $candidates
	 * @return array<int, array>
	 */
	private function dropEmptyThreads(array $candidates): array
	{
		$replyCounts = ReplyCounter::getForChats(
			array_map(static fn (array $row): int => (int)$row['ID'], $candidates),
		);

		$rows = [];

		foreach ($candidates as $row)
		{
			$replyCount = $replyCounts[(int)$row['ID']] ?? 0;

			if ($replyCount > 0)
			{
				$row['REPLY_COUNT'] = $replyCount;
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * [P4.T4]: the history limit hides a post from its reader, and the root post of a thread rides in
	 * the popup block of this answer, so without this the table of contents would hand out the text
	 * Chat.load of the same chat refuses. The boundary is the one Message::checkAccess() applies, and
	 * it is taken from the parent chat, so the list and the thread itself answer alike.
	 *
	 * Nothing is loaded while the limit is off: hasRestrictions() reads no table.
	 *
	 * @param array<int, array> $rows
	 * @return array<int, array>
	 */
	private function dropThreadsBeyondHistoryLimit(array $rows): array
	{
		$limit = Limit::getInstance();

		if (empty($rows) || !$limit->hasRestrictions())
		{
			return $rows;
		}

		$posts = new MessageCollection(array_map(static fn (array $row): int => (int)$row['PARENT_MID'], $rows));

		if (!$limit->shouldFilterByDate($posts))
		{
			return $rows;
		}

		$readablePostIds = array_flip($posts->filterByDate($limit->getLimitDate())->getResult()->getIds());

		return array_values(
			array_filter($rows, static fn (array $row): bool => isset($readablePostIds[(int)$row['PARENT_MID']])),
		);
	}

	/**
	 * A comment chat has no recent row, so the date of its last reply is only reachable through the
	 * message the chat points at. A thread whose last message is gone falls back to its own creation
	 * date: the cell always carries a date, and losing the row must not lose the thread.
	 *
	 * @param array<int, array> $rows
	 * @return array<int, DateTime> discussion chat id => date of its last message
	 */
	private function getLastMessageDates(array $rows): array
	{
		$messageIds = [];

		foreach ($rows as $row)
		{
			$messageId = (int)$row['LAST_MESSAGE_ID'];

			if ($messageId > 0)
			{
				$messageIds[] = $messageId;
			}
		}

		$dates = [];

		if (!empty($messageIds))
		{
			$dates = RowsToMapHelper::mapIntKey(
				MessageTable::query()->setSelect(['ID', 'DATE_CREATE'])->whereIn('ID', $messageIds)->fetchAll(),
				'ID',
				'DATE_CREATE',
			);
		}

		$datesByChatId = [];

		foreach ($rows as $row)
		{
			$datesByChatId[(int)$row['ID']] =
				$dates[(int)$row['LAST_MESSAGE_ID']] ?? $row['DATE_CREATE'] ?? new DateTime()
			;
		}

		return $datesByChatId;
	}

	/**
	 * The unread numbers come from the counter tree, so they agree with the badges the reader already
	 * sees. The tree holds at most counter_chats_limit chats per user: a thread beyond that limit
	 * reports zero and sorts into the lower group - an accepted limitation of the list.
	 *
	 * @return array<int, int> discussion chat id => number of unread messages
	 */
	private function getUnreadCounts(int $parentChatId, int $userId): array
	{
		$counts = [];

		foreach ($this->countersCollector->get($userId) as $counter)
		{
			if ($counter->parentChatId === $parentChatId && $counter->counter > 0)
			{
				$counts[$counter->chatId] = $counter->counter;
			}
		}

		return $counts;
	}
}
