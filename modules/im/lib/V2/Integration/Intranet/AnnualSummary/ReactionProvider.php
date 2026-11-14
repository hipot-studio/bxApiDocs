<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\ReactionTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;

/**
 * Reactions other people left on the messages the user sent within the period.
 *
 * The count walks the messages, never b_im_reaction: that table has no index carrying a date, so
 * any form starting from the reactions side degrades into a full scan. The period is therefore a
 * range of message identifiers, and a reaction put after the period still counts for a message of
 * the period.
 *
 * The walk goes chat by chat, and inside a chat by windows of identifiers: a chat is matched by
 * equality, because a range over CHAT_ID sends the optimizer to the index of the period and costs
 * the whole author on every window. A window that turned out empty is not followed by the next
 * window but by the next message of the author: the identifier of b_im_message runs through the
 * whole portal, so the messages of one author are a rare subset of it and the distance between two
 * of them says nothing about their number. Crossing a gap window by window costs a query per width
 * of a window; asking where the next message lies costs one query for the whole gap. Measured on an
 * author of 21 messages spread over 20 000 000 identifiers in one chat: 405 queries, 470 examined
 * rows and 553 ms of database time by windows alone against 65 queries, 130 rows and 81 ms with the
 * jump.
 *
 * The jump is asked for only after an empty window, never before one: on an author whose messages
 * fill the windows there are no empty windows to skip, and asking beforehand would double the
 * queries of the walk. Measured on an author of 30 000 consecutive messages: 7 queries either way.
 *
 * An author heavy enough not to fit the budget of a step is counted over several of them: the walk
 * stops on the identifiers, the chats or the windows that fill the budget, saves the chat and the
 * window it has reached and resumes from there on the next call.
 *
 * The budget of a step is 5 s of database time: a quarter of Stepper::THRESHOLD_TIME, the platform
 * threshold past which a step starts spacing out the runs of the stepper itself. Each of the three
 * bounds below is measured against a share of that budget, and the shares are additive — a step
 * advances all three counters at once and ends on whichever fills first.
 */
class ReactionProvider extends AbstractSummaryProvider
{
	public const PROVIDER_ID = 'im.received_reactions';

	/**
	 * A batch of one, and not as a load lever any more: the stepper retires a provider by the
	 * single sign of an incomplete batch, so on a larger batch an unfinished user held in the
	 * cursor would read as "the users are over" and lose the metric. At one, a batch is incomplete
	 * exactly when it is empty, and the sign means nothing else.
	 */
	public const USER_ID_LIMIT = 1;

	/**
	 * Size of the identifier buffer one reaction count receives: the larger it is, the fewer
	 * counting queries. It bounds the list in PHP, not the result set the database driver buffers.
	 */
	private const MESSAGE_CHUNK_SIZE = 10000;

	/**
	 * Width of the identifier window a chat is read by, and one of the three bounds of a step: a step
	 * ends on the window that fills it, never in the middle of one.
	 *
	 * The width is chosen for what a step can carry, not for the smaller count of steps: the step
	 * counts the reactions of everything the windows collected and reads the identifiers row by row,
	 * and both grow with the width. Measured on an author of 800 000 messages in the period: a step
	 * of that author spends 0.7 s of database time and two seconds of the process, and the worst of
	 * them twice that, against the budget of 5 s.
	 *
	 * What the width no longer decides is the price of a sparse author: a gap between two messages
	 * costs one empty window and one jump whatever its own width is.
	 */
	private const ID_WINDOW_WIDTH = 50000;

	/**
	 * Chats one step walks at most: the second bound of a step, and the one that answers for the
	 * authors the width of the window never reaches. Opening a chat costs two queries whatever it
	 * holds, so an author of many chats with a message or two in each pays for the walk and fills no
	 * window at all. Measured on authors of 220 and of 400 such chats: a chat with the single window
	 * it holds costs 2.3 to 2.6 ms of database time, so 250 of them take about an eighth of the
	 * budget of a step, leaving the rest of it to the window.
	 */
	private const CHAT_WALK_LIMIT = 250;

	/**
	 * Windows one step reads at most: the third bound of a step, and the one that answers for the
	 * author whose every message stands in a window of its own. The gap between two such messages
	 * costs an empty window and a jump, so the identifiers arrive one per window and the width above
	 * never fills, while the walk stays inside one chat and the chats never run out either.
	 *
	 * Measured on an author of 600 messages 60 000 identifiers apart read by windows of 30 000, the
	 * worst shape this bound was left to hold: a window costs 1.3 ms of database time and a jump
	 * 1.0 ms, so a step that fills the bound spends 1.1 s of database time and 1.9 s at its worst —
	 * a fifth to two fifths of the budget, the part the window and the chat leave free.
	 */
	private const WINDOW_READ_LIMIT = 1000;

	private readonly SummaryProgressStorage $progressStorage;

	public function __construct(
		Date $from,
		Date $to,
		private readonly MessageIdRangeResolver $idRangeResolver,
	)
	{
		parent::__construct($from, $to);

		$this->progressStorage = new SummaryProgressStorage(self::PROVIDER_ID, $from, $to);
	}

	public function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	protected function calculate(int $userId): ?int
	{
		try
		{
			return $this->countReceivedReactions($userId);
		}
		catch (\Throwable $e)
		{
			// The cursor moves past a failed user, so nobody will come back for the position saved
			// for them: it is dropped here or it outlives the count it belonged to.
			$this->progressStorage->delete();

			throw $e;
		}
	}

	protected function getMessageChunkSize(): int
	{
		return self::MESSAGE_CHUNK_SIZE;
	}

	protected function getIdWindowWidth(): int
	{
		return self::ID_WINDOW_WIDTH;
	}

	protected function getChatWalkLimit(): int
	{
		return self::CHAT_WALK_LIMIT;
	}

	protected function getWindowReadLimit(): int
	{
		return self::WINDOW_READ_LIMIT;
	}

	/**
	 * Null when the budget of the step is spent and the author has windows left: the position is
	 * saved. An empty window is an ordinary event here, so the walk ends on the chats running out.
	 */
	private function countReceivedReactions(int $userId): ?int
	{
		$range = $this->idRangeResolver->resolve($this->from, $this->to);

		if ($range->idFrom === null)
		{
			return 0;
		}

		$progress = $this->progressStorage->read($userId);
		$windowWidth = $this->getIdWindowWidth();
		$chatWalkLimit = $this->getChatWalkLimit();
		$windowReadLimit = $this->getWindowReadLimit();
		$total = $progress?->total ?? 0;
		$chatId = $progress?->chatId ?? 0;
		$messageIds = [];
		$collectedIds = 0;
		$walkedChats = 0;
		$readWindows = 0;
		$heldWindowFrom = null;

		// A window nobody can enter, so a walk starting anew opens the first chat on its first turn.
		[$windowFrom, $chatLastId] = $progress === null
			? [1, 0]
			: $this->getChatWindowBounds($userId, $chatId, $range, $progress->messageId)
		;

		while (true)
		{
			if ($windowFrom > $chatLastId)
			{
				$nextChatId = $this->getNextChatId($userId, $chatId);

				if ($nextChatId === null)
				{
					break;
				}

				$chatId = $nextChatId;

				if ($walkedChats >= $chatWalkLimit)
				{
					// The chat is held untouched, and 1 asks the next step for its first identifier:
					// the resume takes the greatest of the bounds, and every bound of a chat is above.
					$heldWindowFrom = 1;

					break;
				}

				$walkedChats++;
				[$windowFrom, $chatLastId] = $this->getChatWindowBounds($userId, $chatId, $range);

				continue;
			}

			// Both bounds are checked on the border of a window: the position saved for the next
			// step is a window bound, so a step broken inside a window would have nowhere to point.
			if ($collectedIds >= $windowWidth || $readWindows >= $windowReadLimit)
			{
				$heldWindowFrom = $windowFrom;

				break;
			}

			$collectedBeforeWindow = $collectedIds;

			foreach ($this->getMessageIdQuery($userId, $chatId, $windowFrom, $range)->exec() as $row)
			{
				$messageIds[] = (int)$row['ID'];
				$collectedIds++;

				if (count($messageIds) === $this->getMessageChunkSize())
				{
					$total += $this->countReactions($messageIds, $userId);
					$messageIds = [];
				}
			}

			$windowFrom += $windowWidth;
			$readWindows++;

			if ($collectedIds === $collectedBeforeWindow)
			{
				// An empty window says nothing about the distance to the next message of the author, so
				// the walk asks for it instead of crossing the gap window by window. No message left is
				// the end of the chat, never the end of the author.
				$windowFrom = $this->getNextMessageId($userId, $chatId, $windowFrom) ?? ($chatLastId + 1);
			}
		}

		if ($messageIds !== [])
		{
			$total += $this->countReactions($messageIds, $userId);
		}

		if ($heldWindowFrom !== null)
		{
			$this->progressStorage->write(
				new SummaryProgress($userId, $chatId, $heldWindowFrom, $total, ($progress?->steps ?? 0) + 1),
			);

			return null;
		}

		if ($progress !== null && $readWindows === 0)
		{
			// A resumed step that found no window at all must not keep holding the cursor: the
			// messages behind the saved position are gone, and waiting for them would stall the
			// whole portal.
			$this->getLogger()?->error(
				'The annual summary metric resumed with no messages left to count',
				[
					'provider' => $this->getId(),
					'userId' => $userId,
					'total' => $total,
					'steps' => $progress->steps,
				],
			);
		}

		$this->progressStorage->delete();

		return $total;
	}

	/**
	 * The next chat of the author, and without a word about the period on purpose: the same cursor
	 * carrying NOTIFY_TYPE = 0 AND ID >= idFrom costs 1 024 ms and 813 850 examined rows on the
	 * heaviest author of the stand against 0.1 ms and a single row without them.
	 */
	private function getNextChatId(int $userId, int $chatId): ?int
	{
		$row = MessageTable::query()
			->setSelect(['NEXT_CHAT_ID'])
			->registerRuntimeField('NEXT_CHAT_ID', new ExpressionField('NEXT_CHAT_ID', 'MIN(CHAT_ID)'))
			->where('AUTHOR_ID', $userId)
			->where('CHAT_ID', '>', $chatId)
			->fetch()
		;

		return isset($row['NEXT_CHAT_ID']) ? (int)$row['NEXT_CHAT_ID'] : null;
	}

	/**
	 * The first identifier of the chat to read and the last one worth reading, both cut down to the
	 * period: a chat older than the period would otherwise spin empty windows from its first
	 * message up to the period. A first above the last means the chat holds nothing to count.
	 *
	 * The bounds carry no condition of the period either, for the same reason the chat cursor
	 * carries none.
	 *
	 * @return array{int, int}
	 */
	private function getChatWindowBounds(
		int $userId,
		int $chatId,
		MessageIdRange $range,
		int $resumeFrom = 0,
	): array
	{
		$row = MessageTable::query()
			->setSelect(['MIN_ID', 'MAX_ID'])
			->registerRuntimeField('MIN_ID', new ExpressionField('MIN_ID', 'MIN(ID)'))
			->registerRuntimeField('MAX_ID', new ExpressionField('MAX_ID', 'MAX(ID)'))
			->where('AUTHOR_ID', $userId)
			->where('CHAT_ID', $chatId)
			->fetch()
		;

		if (!isset($row['MIN_ID'], $row['MAX_ID']))
		{
			return [1, 0];
		}

		$windowFrom = max((int)$row['MIN_ID'], (int)$range->idFrom, $resumeFrom);
		$chatLastId = (int)$row['MAX_ID'];

		if ($range->idTo !== null)
		{
			$chatLastId = min($chatLastId, $range->idTo - 1);
		}

		return [$windowFrom, $chatLastId];
	}

	/**
	 * The first identifier of the author in the chat at or from the given one, and null when the chat
	 * holds none: the walk has reached the end of the chat, not the end of the author.
	 *
	 * The form carries neither the period nor the type of the message, the same way the bounds of the
	 * chat carry neither: MIN(ID) over the columns of IX_IM_MESS_10 (AUTHOR_ID, CHAT_ID) with the
	 * primary key behind them is answered by the index alone, and any column outside it turns the
	 * jump into a scan of the very gap it is there to skip. Measured on the stand: Select tables
	 * optimized away, one examined row, 0.9 ms.
	 */
	private function getNextMessageId(int $userId, int $chatId, int $windowFrom): ?int
	{
		$row = MessageTable::query()
			->setSelect(['NEXT_ID'])
			->registerRuntimeField('NEXT_ID', new ExpressionField('NEXT_ID', 'MIN(ID)'))
			->where('AUTHOR_ID', $userId)
			->where('CHAT_ID', $chatId)
			->where('ID', '>=', $windowFrom)
			->fetch()
		;

		return isset($row['NEXT_ID']) ? (int)$row['NEXT_ID'] : null;
	}

	/**
	 * A window of identifiers inside one chat, and the only query of the three carrying the period.
	 *
	 * The chat is matched by equality and never by a range: the same chat asked by
	 * CHAT_ID >= x AND CHAT_ID < x + 1 reads 813 850 rows in 1 107 ms against 500 001 rows in
	 * 499 ms by equality, with the same answer. Neither ORDER BY nor LIMIT takes part: the plans of
	 * this form are proportional to the answer and its Sort_rows is zero, measured.
	 */
	private function getMessageIdQuery(int $userId, int $chatId, int $windowFrom, MessageIdRange $range): Query
	{
		$query = MessageTable::query()
			->setSelect(['ID'])
			->where('AUTHOR_ID', $userId)
			->where('NOTIFY_TYPE', \IM_NOTIFY_MESSAGE)
			->where('CHAT_ID', $chatId)
			->where('ID', '>=', $windowFrom)
			->where('ID', '<', $windowFrom + $this->getIdWindowWidth())
			->where('ID', '>=', $range->idFrom)
		;

		if ($range->idTo !== null)
		{
			$query->where('ID', '<', $range->idTo);
		}

		return $query;
	}

	/**
	 * @param int[] $messageIds
	 */
	private function countReactions(array $messageIds, int $userId): int
	{
		// Own reactions do not count. USER_ID is the third column of
		// UIX_B_IM_REACTION_1 (MESSAGE_ID, REACTION, USER_ID), so the condition costs no extra rows;
		// it does move the plan off the narrower IX_B_IM_REACTION_3 (MESSAGE_ID).
		$row = ReactionTable::query()
			->setSelect(['COUNT'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('USER_ID', '!=', $userId)
			->fetch()
		;

		return (int)($row['COUNT'] ?? 0);
	}
}
