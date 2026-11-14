<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cleanup;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use CAgent;

/**
 * Takes one reader off the comment chats of the posts a channel cleanup has hidden from them. The hit
 * that answers the user runs the walk within a budget and hands whatever is left to this agent: the
 * comment chats of a channel are one per post, so a reader of a busy channel has as many subscriptions
 * as the channel has posts they took part in, and walking all of them holds the worker for as many
 * statements.
 *
 * One agent per reader and channel, however many times they clear the channel while the walk is on its
 * way. The border the walk works with is the one the reader's own relation carries - a cleanup for
 * oneself moves it and nothing ever moves it back - and every pass reads it afresh: a border moved by a
 * later cleanup restarts the walk of the same agent instead of adding another one beside it, the way
 * {@see ChatHistoryCleanupAgent} handles the border of the chat.
 */
final class CommentSubscriptionCleanupAgent
{
	/** Batches a scheduled pass runs before it hands the walk back to the agent system. */
	private const BATCHES_PER_PASS = 20;

	/** Seconds a pass may spend: an agent may run inside a hit, which cannot wait for the whole walk. */
	private const PASS_TIME_LIMIT = 10;

	/** Seconds a registering hit and a finishing pass wait for each other. */
	private const LOCK_TIMEOUT = 5;

	public function __construct(
		private readonly int $chatId,
		private readonly int $userId,
		private readonly int $lastMessageId,
	)
	{
	}

	/**
	 * The pass runs the same walk the hit does, and returns its own name until the walk is done. The
	 * cursor comes back in that name, so the next pass resumes where this one stopped instead of reading
	 * the prefix it has already muted again: a muted row is dropped by a row lookup, not by the index.
	 */
	public static function processCleanup(int $chatId, int $userId, int $lastMessageId, int $cursor = 0): string
	{
		$border = self::readBorder($chatId, $userId);

		if ($border > $lastMessageId)
		{
			// The border moved while the walk was on its way. The subscriptions the cursor has passed
			// belong to posts that were still visible when it passed them, so the walk starts over for the
			// new border.
			$lastMessageId = $border;
			$cursor = 0;
		}

		$cleanup = new self($chatId, $userId, $lastMessageId);

		$isFinished = $cleanup->unsubscribeWithinBudget(
			self::readBatchesPerPass(),
			microtime(true) + self::PASS_TIME_LIMIT,
			$cursor
		);

		return $isFinished ? $cleanup->finish() : $cleanup->formatAgentName($cursor);
	}

	/**
	 * The check and the addition go under the lock the finishing pass takes as well: without it a pass
	 * could take itself down between the check that found it and a border it has never seen. An agent
	 * already standing for this reader and channel is left alone - its next pass reads the moved border
	 * from the relation and starts the walk over.
	 */
	public function register(): void
	{
		$isDone = $this->withLock(function (): void {
			if (!$this->isScheduled())
			{
				self::addAgent($this->formatAgentName());
			}
		});

		if (!$isDone)
		{
			// Nobody holds the lock for longer than a few statements; a second agent is the worst case.
			self::addAgent($this->formatAgentName());
		}
	}

	public function isScheduled(): bool
	{
		return $this->findAgentIds() !== [];
	}

	private static function readBatchesPerPass(): int
	{
		return max(1, (int)Option::get('im', 'chat_history_cleanup_batches_per_pass', self::BATCHES_PER_PASS));
	}

	/**
	 * Every batch switches off the subscriptions it has taken, but the rows themselves stay where they
	 * are, and no index of b_im_relation carries NOTIFY_BLOCK: a selection starting over reads the whole
	 * prefix it has already muted before it reaches anything new, which costs a walk of many batches as
	 * much as it has batches. So the walk reads forward by CHAT_ID instead, within
	 * IX_IM_REL_9 (USER_ID, CHAT_ID), and each batch begins where the one before it stopped - the cursor
	 * is what makes the same batch impossible to draw twice.
	 *
	 * The mute is mirrored into the unread rows, which carry their own IS_MUTED and feed the counters on
	 * their own, and the counters cache is dropped once at the end, the way
	 * {@see \Bitrix\Im\V2\Reading\Counter\Infrastructure\CountersEventHandler::onMuteChanged()} does it
	 * for a single chat.
	 *
	 * @param int $cursor in: the chat the walk resumes after; out: the chat it has stopped at
	 * @return bool whether the walk has nothing left to take
	 */
	public function unsubscribeWithinBudget(int $batchBudget, float $deadline, int &$cursor = 0): bool
	{
		$batchSize = ChatHistoryCleanupAgent::readCleanupLimit();
		$isAnythingMuted = false;
		$isFinished = false;

		for ($batch = 0; $batch < $batchBudget; $batch++)
		{
			$commentChatIds = $this->collectSubscriptions($batchSize, $cursor);

			if ($commentChatIds === [])
			{
				$isFinished = true;
				break;
			}

			$cursor = max($commentChatIds);
			$this->unsubscribe($commentChatIds);
			$isAnythingMuted = true;

			if (count($commentChatIds) < $batchSize)
			{
				$isFinished = true;
				break;
			}

			// A batch always runs to its end: the rows it has switched off are the ones the next walk
			// no longer sees.
			if (microtime(true) >= $deadline)
			{
				break;
			}
		}

		if ($isAnythingMuted)
		{
			// The cache holds the counters of the whole user, so one drop after the walk is enough.
			ServiceLocator::getInstance()->get(CountersCache::class)->remove($this->userId);
		}

		return $isFinished;
	}

	/**
	 * The user's own subscriptions, not the comment chats of the channel: listing those would put an
	 * unbounded id list in memory and in a single IN. Read forward from the last chat the walk has
	 * taken, in the order IX_IM_REL_9 (USER_ID, CHAT_ID) already holds them, so the batch is drawn from
	 * the rows ahead of the cursor rather than from the whole set again.
	 *
	 * @return int[]
	 */
	private function collectSubscriptions(int $batchSize, int $cursor): array
	{
		$rows = RelationTable::query()
			->setSelect(['CHAT_ID'])
			->where('USER_ID', $this->userId)
			->where('NOTIFY_BLOCK', 'N')
			->where('CHAT_ID', '>', $cursor)
			->where('CHAT.PARENT_ID', $this->chatId)
			->where('CHAT.TYPE', Chat::IM_TYPE_COMMENT)
			->where('CHAT.PARENT_MID', '<=', $this->lastMessageId)
			->setOrder(['CHAT_ID' => 'ASC'])
			->setLimit($batchSize)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'CHAT_ID'));
	}

	/**
	 * @param int[] $commentChatIds
	 */
	private function unsubscribe(array $commentChatIds): void
	{
		RelationTable::updateByFilter(
			[
				'=USER_ID' => $this->userId,
				'@CHAT_ID' => $commentChatIds,
				'=NOTIFY_BLOCK' => 'N',
			],
			[
				'NOTIFY_BLOCK' => 'Y',
			]
		);

		MessageUnreadTable::updateBatch(
			['IS_MUTED' => 'Y'],
			[
				'=USER_ID' => $this->userId,
				'@CHAT_ID' => $commentChatIds,
			]
		);
	}

	/**
	 * A finished pass takes its own agent down under the lock a registering hit takes: the border may
	 * have moved after the pass read it, and a hit that finds the agent still in place adds none of its
	 * own. Re-reading the border under the lock and removing the agent before the lock is released
	 * leaves no moment when a moved border stays with no walk to serve it. The agent system would drop
	 * the agent on the empty name anyway, only later than the lock.
	 *
	 * @return string the name the pass answers with: empty once the walk is done
	 */
	private function finish(): string
	{
		$name = null;

		$isDone = $this->withLock(function () use (&$name): void {
			if (self::readBorder($this->chatId, $this->userId) > $this->lastMessageId)
			{
				// The next pass reads the moved border and starts the walk over.
				$name = $this->formatAgentName();

				return;
			}

			foreach ($this->findAgentIds() as $agentId)
			{
				CAgent::Delete($agentId);
			}

			$name = '';
		});

		// A hit is registering the very border this pass has not seen: keep the agent for one more pass.
		return $isDone ? $name : $this->formatAgentName();
	}

	/**
	 * @return bool whether the action ran, which it does under the lock only
	 */
	private function withLock(callable $action): bool
	{
		$connection = Application::getConnection();
		$lockName = 'im_comment_subscription_cleanup_' . $this->chatId . '_' . $this->userId;

		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return false;
		}

		try
		{
			$action();
		}
		finally
		{
			$connection->unlock($lockName);
		}

		return true;
	}

	private static function addAgent(string $name): void
	{
		CAgent::AddAgent($name, 'im', 'N', 60, '', 'Y', '', 100, false, false);
	}

	/**
	 * The border of the reader in the channel is the one their relation carries: the message before
	 * START_ID. A relation that is gone names no border - the reader has left the channel, and the
	 * subscriptions they still hold are nothing a moved border would come back for.
	 */
	private static function readBorder(int $chatId, int $userId): int
	{
		$row = RelationTable::query()
			->setSelect(['START_ID'])
			->where('CHAT_ID', $chatId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		$startId = $row === false ? 0 : (int)$row['START_ID'];

		return $startId > 0 ? $startId - 1 : 0;
	}

	/**
	 * The user id is followed by the border, never by more of its own digits: that is what tells the
	 * agents of this reader from the agents of a reader whose id merely starts the same way.
	 *
	 * @return int[]
	 */
	private function findAgentIds(): array
	{
		$prefix = __CLASS__ . '::processCleanup(' . $this->chatId . ', ' . $this->userId;
		$agents = CAgent::GetList([], ['MODULE_ID' => 'im', 'NAME' => $prefix . '%']);
		$ids = [];

		while ($agent = $agents->Fetch())
		{
			if (str_starts_with(substr((string)$agent['NAME'], strlen($prefix)), ','))
			{
				$ids[] = (int)$agent['ID'];
			}
		}

		return $ids;
	}

	/**
	 * The cursor is the trailing argument and is left out at its default, so a name written before the
	 * walk carried one is the same string byte for byte: the agents already standing in b_agent keep
	 * being called the way they were registered, and a pass reading such a name starts from the top.
	 *
	 * The border in the name is the one the pass that wrote it worked with; the pass that reads it
	 * compares it with the border of the relation, so a border moved in the meantime is noticed there.
	 */
	private function formatAgentName(int $cursor = 0): string
	{
		$arguments = [$this->chatId, $this->userId, $this->lastMessageId];

		if ($cursor > 0)
		{
			$arguments[] = $cursor;
		}

		return __CLASS__ . '::processCleanup(' . implode(', ', $arguments) . ');';
	}
}
