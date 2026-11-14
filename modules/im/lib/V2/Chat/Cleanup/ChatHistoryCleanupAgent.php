<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cleanup;

use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use CAgent;

/**
 * One scheduled removal per chat, however many times its border moves while the removal is on its way.
 * The name of the agent carries the border a pass has worked with, the step it has completed and the
 * cursor of each step that walks a set of its own - the members, and the preview pointers of a parent
 * collab; the border itself is read from the chat on every pass, so a border moved by a later cleanup
 * restarts the sequence of the same agent instead of adding another one.
 */
final class ChatHistoryCleanupAgent
{
	private const CLEANUP_LIMIT = 500;

	/**
	 * Batches a pass runs before it hands the sequence back to the agent system. One batch a minute
	 * would keep the members of a large chat, and the files behind the border, waiting for hours.
	 */
	private const BATCHES_PER_PASS = 20;

	/** Seconds a pass may spend: an agent may run inside a hit, which cannot wait for the whole budget. */
	private const PASS_TIME_LIMIT = 10;

	/**
	 * Seconds the walk over the members may spend on the hit that answers the user, on top of the budget
	 * of batches it has: a batch is never given up halfway, so this is what stops a hit whose batches
	 * come out heavier than the budget of them assumed. The members left over are what the scheduled
	 * cleanup starts with anyway, and the content is behind the border of the chat already.
	 */
	private const HIT_TIME_LIMIT = 1;

	/** Seconds a registering hit and a finishing pass wait for each other. */
	private const LOCK_TIMEOUT = 5;

	/**
	 * The check and the addition go under the lock the finishing pass takes as well: without it a pass
	 * could take itself down between the check that found it and a border it has never seen.
	 */
	public static function register(int $chatId, int $lastMessageId): void
	{
		$isDone = self::withLock($chatId, static function () use ($chatId, $lastMessageId): void {
			if (!self::isScheduled($chatId))
			{
				self::addAgent(self::formatCleanupAgentName($chatId, $lastMessageId));
			}
		});

		if (!$isDone)
		{
			// Nobody holds the lock for longer than a few statements; a second agent is the worst case.
			self::addAgent(self::formatCleanupAgentName($chatId, $lastMessageId));
		}
	}

	public static function isScheduled(int $chatId): bool
	{
		return self::findAgentIds($chatId) !== [];
	}

	public static function processCleanup(
		int $chatId,
		int $lastMessageId,
		?string $prevCompletedStep = null,
		int $membersCursor = 0,
		int $collabPreviewCursor = 0,
	): string
	{
		// A request that selected the parameters before the border was written may have published its copy
		// after the hit dropped the cache, without the border; dropped again here, such a copy outlives
		// the hit by no more than the wait for the first pass.
		Params::cleanCache($chatId);
		$border = self::readHistoryBorder($chatId);

		if ($border > $lastMessageId)
		{
			// The border moved while the cleanup was on its way. The steps already completed have left
			// the content between the two borders behind, so the sequence starts over for the new one -
			// the members among them: the relations the walk moved above the old border stand below the
			// new one again, and the cursor would carry it past them. The preview pointers are resolved
			// against those relations, so their cursor goes the same way.
			$lastMessageId = $border;
			$prevCompletedStep = null;
			$membersCursor = 0;
			$collabPreviewCursor = 0;
		}

		if ($lastMessageId <= 0)
		{
			return self::finish($chatId, $lastMessageId, $prevCompletedStep, $membersCursor, $collabPreviewCursor);
		}

		$limit = self::readCleanupLimit();
		$deadline = microtime(true) + self::PASS_TIME_LIMIT;
		$collector = (new ChatContentCollector($chatId, $lastMessageId))
			->withMembersCursor($membersCursor)
			->withCollabPreviewCursor($collabPreviewCursor)
			->withDeadline($deadline)
		;

		for ($batch = self::readBatchesPerPass(); $batch > 0; $batch--)
		{
			$progress = $collector->processCleanup($limit, $prevCompletedStep);

			if ($progress->isFinished)
			{
				return self::finish(
					$chatId,
					$lastMessageId,
					$prevCompletedStep,
					$collector->getMembersCursor(),
					$collector->getCollabPreviewCursor()
				);
			}

			$prevCompletedStep = $progress->completedStep;

			if (microtime(true) >= $deadline)
			{
				break;
			}
		}

		return self::formatCleanupAgentName(
			$chatId,
			$lastMessageId,
			$prevCompletedStep,
			$collector->getMembersCursor(),
			$collector->getCollabPreviewCursor()
		);
	}

	/**
	 * Rows a batch may delete, the size of a batch of members as well.
	 */
	public static function readCleanupLimit(): int
	{
		return max(1, (int)Option::get('im', 'chat_history_cleanup_limit', self::CLEANUP_LIMIT));
	}

	/**
	 * Seconds the hit may spend hiding the history from the members before it hands the rest over.
	 */
	public static function readHitTimeLimit(): float
	{
		return max(0.5, (float)Option::get('im', 'chat_history_cleanup_hit_seconds', (string)self::HIT_TIME_LIMIT));
	}

	private static function readBatchesPerPass(): int
	{
		return max(1, (int)Option::get('im', 'chat_history_cleanup_batches_per_pass', self::BATCHES_PER_PASS));
	}

	/**
	 * A finished pass takes its own agent down under the lock a registering hit takes: the border may
	 * have moved after the pass read it, and a hit that finds the agent still in place adds none of its
	 * own. Re-reading the border under the lock and removing the agent before the lock is released
	 * leaves no moment when a moved border stays with no cleanup to serve it. The agent system would
	 * drop the agent on the empty name anyway, only later than the lock.
	 */
	private static function finish(
		int $chatId,
		int $lastMessageId,
		?string $prevCompletedStep,
		int $membersCursor = 0,
		int $collabPreviewCursor = 0,
	): string
	{
		$name = null;

		$isDone = self::withLock($chatId, static function () use ($chatId, $lastMessageId, &$name): void {
			if (self::readHistoryBorder($chatId) > $lastMessageId)
			{
				// The next pass reads the moved border and starts the sequence over.
				$name = self::formatCleanupAgentName($chatId, $lastMessageId);

				return;
			}

			foreach (self::findAgentIds($chatId) as $agentId)
			{
				CAgent::Delete($agentId);
			}

			$name = '';
		});

		if (!$isDone)
		{
			// A hit is registering the very border this pass has not seen: keep the agent for one more pass.
			return self::formatCleanupAgentName(
				$chatId,
				$lastMessageId,
				$prevCompletedStep,
				$membersCursor,
				$collabPreviewCursor
			);
		}

		return $name;
	}

	/**
	 * @return bool whether the action ran, which it does under the lock only
	 */
	private static function withLock(int $chatId, callable $action): bool
	{
		$connection = Application::getConnection();
		$lockName = 'im_chat_history_cleanup_' . $chatId;

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
	 * The chat id is followed by the border, never by more of its own digits: that is what tells the
	 * agents of this chat from the agents of a chat whose id merely starts the same way.
	 *
	 * @return int[]
	 */
	private static function findAgentIds(int $chatId): array
	{
		$prefix = __CLASS__ . '::processCleanup(' . $chatId;
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
	 * Read from the table rather than from the chat: the pass has to see a border written by another hit
	 * in the meantime, while a loaded chat keeps its parameters for the whole hit.
	 */
	private static function readHistoryBorder(int $chatId): int
	{
		$rows = ChatParamTable::query()
			->setSelect(['PARAM_VALUE'])
			->where('CHAT_ID', $chatId)
			->where('PARAM_NAME', Params::HISTORY_START_ID)
			->fetchAll()
		;

		// The highest of them, because the border only ever moves forward: a chat cleaned by an earlier
		// version of this path may carry more than one row of it.
		$historyStartId = 0;
		foreach ($rows as $row)
		{
			$historyStartId = max($historyStartId, (int)$row['PARAM_VALUE']);
		}

		return $historyStartId > 0 ? $historyStartId - 1 : 0;
	}

	/**
	 * The whole state of the walk, so that a pass out of budget resumes where it stopped rather than
	 * reading what it has already handled again: the step it completed and the cursor of every step that
	 * carries one - the walk over the members and the recompute of the collab preview. A cursor of a
	 * step already completed points at nothing the next pass would read, and a trailing argument left at
	 * its default is dropped, so a name says no more than the pass that wrote it has to remember.
	 */
	private static function formatCleanupAgentName(
		int $chatId,
		int $lastMessageId,
		?string $prevCompletedStep = null,
		int $membersCursor = 0,
		int $collabPreviewCursor = 0,
	): string
	{
		$arguments = [$chatId, $lastMessageId];

		if ($prevCompletedStep !== null || $membersCursor > 0 || $collabPreviewCursor > 0)
		{
			$arguments[] = var_export($prevCompletedStep, true);
		}

		if ($membersCursor > 0 || $collabPreviewCursor > 0)
		{
			$arguments[] = $membersCursor;
		}

		if ($collabPreviewCursor > 0)
		{
			$arguments[] = $collabPreviewCursor;
		}

		return __CLASS__ . '::processCleanup(' . implode(', ', $arguments) . ');';
	}
}
