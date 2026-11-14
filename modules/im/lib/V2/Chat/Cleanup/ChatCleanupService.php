<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cleanup;

use Bitrix\Im\Model\AnchorTable;
use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChannelChat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Pull\CommentTagPublisher;
use Bitrix\Im\V2\Pull\Event\ChatHistoryDeletedForAll;
use Bitrix\Im\V2\Pull\Event\ChatHistoryDeletedForSelf;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePointerService;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Sync;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;

class ChatCleanupService
{
	/**
	 * Batches the hit walks itself before it answers. Beyond that the walk continues in the scheduled
	 * cleanup: a chat the size of the portal would otherwise hold the worker for as long as it takes to
	 * touch every member, and a worker killed halfway leaves no record of where it stopped.
	 */
	private const BATCHES_PER_HIT = 20;

	/**
	 * Batches of the walk over the members the hit takes before it answers, each a batch of its own
	 * ({@see self::MEMBERS_PER_HIT_BATCH}). Small on purpose: the border on the chat hides the content
	 * from everyone the moment it is written, so the walk owes the answer nothing but the push and the
	 * rows of the members it reaches, and a chat of more members than this budget is handed to the
	 * scheduled cleanup, whose first pass comes within the minute. Whichever of the two budgets - the
	 * batches or the seconds ({@see ChatHistoryCleanupAgent::readHitTimeLimit()}) - runs out first ends
	 * the walk.
	 */
	private const MEMBER_BATCHES_PER_HIT = 10;

	/**
	 * Members a batch of the walk takes on the hit, apart from the batch of the scheduled cleanup. A
	 * batch is never given up halfway, so the time budget of the hit is only ever measured between two
	 * of them, and every member of a batch costs an invalidation of their counters cache of its own -
	 * the one part of the walk that grows with the members rather than with the rows behind the border.
	 * Kept below the batch of the scheduled cleanup for that reason alone: it is the size of a batch,
	 * not the number of them, that decides how far past its budget a hit may go.
	 */
	private const MEMBERS_PER_HIT_BATCH = 100;

	/** Seconds a hit waits for a parallel cleanup of the same chat to finish moving the border. */
	private const BORDER_LOCK_TIMEOUT = 5;

	private Chat $chat;

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	/**
	 * Hides the history from the current user only: their own relation starts after the last message.
	 */
	public function deleteHistoryForSelf(): Result
	{
		$result = new Result();
		$userId = $this->chat->getContext()->getUserId();

		if (!$this->chat->canDo(Action::DeleteHistoryForSelf))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		$relation = $this->chat->getSelfRelation();
		if ($relation === null)
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		$lastMessageId = $this->chat->getLastMessageId();

		if ($lastMessageId <= 0)
		{
			return $result;
		}

		// Ahead of everything the hit writes, and a failure to write it stops the hit: the border on the
		// chat is what the comment pushes of a channel trust to skip reading the relations, so a relation
		// moved past a border that stayed behind would push a reader the comments of a post hidden from
		// them. Asked for on every cleanup, the repeat included: a relation already standing above the
		// border says nothing about the chat, whose own border a hit that died before this line has not
		// raised. Nothing is lost when the hit dies right after it: a border with no relation moved
		// costs the pushes one read of the relations, never a delivery.
		$borderResult = $this->raiseSelfHistoryBorder($lastMessageId + 1);
		if (!$borderResult->isSuccess())
		{
			return $result->addErrors($borderResult->getErrors());
		}

		// The move is the one step of the cleanup that cannot be asked for twice. Everything after it is
		// asked for again: a hit that died between the border and the tail would otherwise be locked out
		// of the tail by the very border it managed to write, leaving counters, recent and the rest behind.
		$this->journalAndMoveSelfRelation($userId, $relation, $lastMessageId);

		// The answers the comment pushes of the channel have cached about who its posts are hidden from
		// were read against the borders that stood before this cleanup.
		CommentTagPublisher::cleanCache($this->chat->getId());

		$this->cleanupTailForSelf($userId, $lastMessageId);

		(new ChatHistoryDeletedForSelf(
			$this->chat,
			$userId,
			$lastMessageId,
			$this->getCounterLeftForSelf($userId),
		))->send();

		$this->dropStaleParamsCopy($this->chat->getId());

		return $result;
	}

	/**
	 * A request that selected the parameters before the border was written may publish its own copy of
	 * them after this hit dropped the cache, and that copy carries no border at all. Both cleanups drop
	 * such a copy once their answer is out, as late as this process can reach: the copy is published by
	 * a request still in flight, and a drop asked for earlier would only be overwritten by it. The first
	 * pass of the agent of a cleanup for everyone drops the parameters again before it reads the border
	 * ({@see ChatHistoryCleanupAgent::processCleanup()}), and stays the answer to a hit that died before
	 * this line. A border missed here is history hidden from everyone that a reader still reaches, or a
	 * post whose comments keep being pushed to the very user who hid it, for as long as the copy lives.
	 */
	private function dropStaleParamsCopy(int $chatId): void
	{
		Application::getInstance()->addBackgroundJob(static fn () => Params::cleanCache($chatId));
	}

	/**
	 * What the user has left unread once the rows behind the border are gone: read after the tail,
	 * and read rather than taken from the cache, which the delete has just dropped. The push carries
	 * it because the messages that arrived above the border stay unread, and no other event of the
	 * hit tells the other clients of the user the new value.
	 */
	private function getCounterLeftForSelf(int $userId): int
	{
		return ServiceLocator::getInstance()
			->get(CountersProvider::class)
			->getForUsers($this->chat->getId(), [$userId])
			->getByUserId($userId)
		;
	}

	/**
	 * Everything the cleanup owes beyond the border itself. Every step is bounded by lastMessageId and
	 * every one of them either deletes rows at or below it or recounts what is left, so a repeat draws
	 * the same statements and changes nothing. Bounded rather than total for the reason the border is
	 * bounded: a message arriving during the cleanup stays visible, and so must everything hanging off it.
	 */
	private function cleanupTailForSelf(int $userId, int $lastMessageId): void
	{
		$this->deleteAnchorsForSelf($this->chat->getId(), $userId, $lastMessageId);
		AnchorContainer::getInstance()->getAnchorProvider()->cleanCache($userId);

		if ($this->chat instanceof ChannelChat)
		{
			$this->unsubscribeFromCommentChats($userId, $lastMessageId);
		}

		$this->updateRecentForSelf($userId, $lastMessageId);
		$this->clearCountersAndViewsForSelf($this->chat->getId(), $userId, $lastMessageId);
		$this->recomputeCollabPreviewSourceForSelf($userId);
	}

	/**
	 * The row of the journal and the move of the relation commit together. Written apart, a sync request
	 * of the same user landing between them reads the row and computes the border from a relation still
	 * standing below it - "everything is hidden", to a client that then drops the messages the server has
	 * kept. In one transaction the row is never visible without the relation it describes, and a hit
	 * dying inside leaves neither; the border of the chat written before it is harmless on its own.
	 *
	 * A relation already above the last message belongs to a repeat, which skips the move and still owes
	 * the client the row: a hit that moved the border and told nobody is exactly what a repeat is for.
	 */
	private function journalAndMoveSelfRelation(int $userId, Relation $relation, int $lastMessageId): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->logSyncForSelf($userId);

			if ($relation->getStartId() <= $lastMessageId)
			{
				$this->moveSelfRelationAboveBorder($userId, $lastMessageId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	/**
	 * Hides the history from every participant and schedules the physical removal of the content.
	 */
	public function deleteHistoryForAll(): Result
	{
		$result = new Result();

		if (!$this->chat->canDo(Action::DeleteHistoryForAll))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		$lastMessageId = $this->chat->getLastMessageId();

		if ($lastMessageId <= 0)
		{
			return $result;
		}

		$chatId = $this->chat->getId();
		$newStartId = $lastMessageId + 1;

		$borderResult = $this->storeHistoryBorder($newStartId);
		if (!$borderResult->isSuccess())
		{
			return $result->addErrors($borderResult->getErrors());
		}

		$this->scheduleCleanup($chatId, $lastMessageId, $newStartId);

		// Ahead of the walk, and written at once instead of behind a background job: a reader of an open
		// chat has no relation to carry a cleanup, so this row is the only durable trace of it they ever
		// get. A hit that dies inside the walk runs no background job, and no step of the agent writes a
		// global row - the walk logs the members it moves and leaves an open chat to this call.
		$this->logSyncForAll();

		$this->hideHistoryFromMembers($chatId, $lastMessageId);
		$this->finishCleanupForAll($lastMessageId);

		// The public part of the event only: a reader without a relation learns about the border of the
		// chat from nothing else. The members are addressed by the walk, batch by batch, so the hit
		// pushes to no more of them than it has actually moved. A push lost with the hit is recovered
		// from the sync row above; the row is not, which is why it goes first.
		(new ChatHistoryDeletedForAll($this->chat, $lastMessageId))->send();

		$this->dropStaleParamsCopy($chatId);

		return $result;
	}

	/**
	 * The border also goes on the chat itself, not only into the relations: an open chat or channel is
	 * readable without membership, so a reader may have no relation to carry a border, and a user added
	 * before the scheduled removal finishes builds their relation from the chat. Written first, because
	 * it is the only border that covers everyone at once, and a failure to write it stops the hit: with
	 * the relations moved and no border on the chat, the history would be hidden from the members only.
	 */
	private function storeHistoryBorder(int $newStartId): Result
	{
		return $this->storeBorder(Params::HISTORY_START_ID, $newStartId);
	}

	/**
	 * A border of the chat only ever rises, and both of them rise the same way: under the one lock of
	 * the chat, against the table rather than the parameters this process loaded, to the highest of the
	 * value stored and the value asked for, leaving a single row behind.
	 *
	 * Reading the border, deciding what to write and writing it are three statements, and between them
	 * a parallel cleanup of the same chat may move the border on its own. Serialized, the two cleanups
	 * agree on which of them is the later one; unserialized, the one that started earlier and answers
	 * last writes its own lower border over the higher one - or adds a second row of it, and a reader of
	 * the parameters keeps one row of several, not the highest. Even a cleanup with nothing to write goes
	 * through the lock, so that the state of the parameter is settled the same way every time: the
	 * border a request carries in memory is exactly what cannot be trusted here.
	 *
	 * A cleanup that cannot get the lock stops, whichever border it came for: nothing is ever written
	 * past the lock, because a write past it is the unserialized one the lock is there to rule out. Both
	 * borders are read on trust - the border of the chat by every read of the history, the border of the
	 * cleanups for oneself by the comment pushes deciding whether the relations have to be read at all -
	 * so neither may be left lower than a relation the same hit has moved.
	 */
	private function storeBorder(string $paramName, int $newStartId): Result
	{
		$chatId = $this->chat->getId();
		$connection = Application::getConnection();
		$lockName = 'im_chat_history_border_' . $chatId;

		if (!$connection->lock($lockName, self::BORDER_LOCK_TIMEOUT))
		{
			// The holder of the lock is moving a border of this very chat. If it has already carried this
			// one past the value asked for here, there is nothing left to write anyway.
			if ($this->readStoredBorder($chatId, $paramName) >= $newStartId)
			{
				return new Result();
			}

			return (new Result())->addError(new ChatError(ChatError::HISTORY_CLEANUP_CONFLICT));
		}

		try
		{
			return $this->writeBorder($chatId, $paramName, $newStartId);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * Under the lock, and against the table rather than the parameters of the loaded chat: those are read
	 * once and kept for the whole hit, and the cache they came from belongs to the process that filled
	 * it, so neither of them can be trusted to hold a border another request has just moved. The higher
	 * of the two values wins - a border that moved backwards would bring messages already hidden back
	 * to the readers who no longer had them. A single row of the parameter is left behind as well: a
	 * reader of the parameters keeps one row of several, not the highest of them, so a second row is a
	 * border of its own.
	 */
	private function writeBorder(int $chatId, string $paramName, int $newStartId): Result
	{
		$rows = $this->readBorderRows($chatId, $paramName);
		$storedBorder = $this->highestBorder($rows);

		if ($storedBorder >= $newStartId && count($rows) < 2)
		{
			// A parallel cleanup has carried the border past this one; only this process still holds the
			// value it read before.
			$this->chat->getChatParams()->reload();

			return new Result();
		}

		$result = new Result();
		$border = (string)max($storedBorder, $newStartId);
		$ids = array_map('intval', array_column($rows, 'ID'));

		if ($ids === [])
		{
			$writeResult = ChatParamTable::add([
				'CHAT_ID' => $chatId,
				'PARAM_NAME' => $paramName,
				'PARAM_VALUE' => $border,
			]);
		}
		else
		{
			$writeResult = ChatParamTable::update($ids[0], ['PARAM_VALUE' => $border]);
		}

		if (!$writeResult->isSuccess())
		{
			return $result->addErrors($writeResult->getErrors());
		}

		$duplicateIds = array_slice($ids, 1);
		if ($duplicateIds !== [])
		{
			ChatParamTable::deleteBatch(['=ID' => $duplicateIds]);
		}

		// The border has gone to the table, past the parameters this process loaded and past the cache
		// every other process reads them from.
		$this->chat->getChatParams()->reload();

		return $result;
	}

	private function readStoredBorder(int $chatId, string $paramName): int
	{
		return $this->highestBorder($this->readBorderRows($chatId, $paramName));
	}

	/**
	 * The border only ever moves forward, so of several rows of the parameter the highest one is the
	 * border of the chat, whichever of them a reader of the parameters happens to keep.
	 */
	private function highestBorder(array $rows): int
	{
		$border = 0;

		foreach ($rows as $row)
		{
			$border = max($border, (int)$row['PARAM_VALUE']);
		}

		return $border;
	}

	private function readBorderRows(int $chatId, string $paramName): array
	{
		return ChatParamTable::query()
			->setSelect(['ID', 'PARAM_VALUE'])
			->where('CHAT_ID', $chatId)
			->where('PARAM_NAME', $paramName)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;
	}

	/**
	 * Scheduled as soon as the border is on the chat, ahead of the walk over the members: whatever fails
	 * below leaves both the members and the content to the agent, and the reverse order is not safe -
	 * the removal must never run ahead of the border. A repeat call, which the border check above lets
	 * through every time, has nothing to schedule once every relation stands above the border and the
	 * content behind it is gone; a cleanup stopped halfway keeps its agent and adds none of its own.
	 */
	private function scheduleCleanup(int $chatId, int $lastMessageId, int $newStartId): void
	{
		if (
			$this->isAlreadyCleanedForAll($chatId, $newStartId)
			&& !$this->hasContentBehindBorder($chatId, $lastMessageId)
		)
		{
			return;
		}

		ChatHistoryCleanupAgent::register($chatId, $lastMessageId);
	}

	/**
	 * The members are walked on the hit up to a budget of batches and a budget of seconds: an ordinary
	 * chat is done before the answer, while the members of a chat larger than either budget are handed
	 * to the scheduled cleanup, whose first step is the same walk. Until it reaches them, their
	 * relations and recent rows still stand below the border, but the content itself is behind the
	 * border of the chat already, on every path that reads it. The batches alone are no bound on the
	 * answer: a batch costs unread and recent rows, relations, caches, a push and a sync row, and the
	 * seconds are what stops a hit whose batches turn out heavier than usual. Both budgets are this
	 * walk's own and both are kept small: the answer to the user is worth a second, not the whole chat.
	 */
	private function hideHistoryFromMembers(int $chatId, int $lastMessageId): void
	{
		(new ChatContentCollector($chatId, $lastMessageId))
			->cleanupMembersWithinBudget(
				self::readHitMemberBatchSize(),
				self::readHitMemberBatchBudget(),
				microtime(true) + ChatHistoryCleanupAgent::readHitTimeLimit()
			)
		;
	}

	private static function readHitBatchBudget(): int
	{
		return max(1, (int)Option::get('im', 'chat_history_cleanup_hit_batches', self::BATCHES_PER_HIT));
	}

	private static function readHitMemberBatchBudget(): int
	{
		return max(
			1,
			(int)Option::get('im', 'chat_history_cleanup_hit_member_batches', self::MEMBER_BATCHES_PER_HIT)
		);
	}

	/**
	 * Never above the batch of the scheduled cleanup: an administrator who has lowered that one has
	 * lowered it for the hit as well.
	 */
	private static function readHitMemberBatchSize(): int
	{
		$batchSize = (int)Option::get('im', 'chat_history_cleanup_hit_members', self::MEMBERS_PER_HIT_BATCH);

		return max(1, min($batchSize, ChatHistoryCleanupAgent::readCleanupLimit()));
	}

	/**
	 * Everything the cleanup owes the chat as a whole once the history is hidden from everyone. The
	 * fields of the chat are written by the last step of the scheduled cleanup as well, which is the only
	 * one to write them at all when a hit dies before it reaches this call.
	 */
	private function finishCleanupForAll(int $lastMessageId): void
	{
		(new ChatContentCollector($this->chat->getId(), $lastMessageId))->updateChatFields();
		$this->recomputeCollabPreviewSourceForAll($lastMessageId);
	}

	/**
	 * Tells a hit that died before scheduling the removal from a cleanup that has already finished and
	 * taken its own agent down: only the first one leaves messages behind the border.
	 */
	private function hasContentBehindBorder(int $chatId, int $lastMessageId): bool
	{
		return MessageTable::query()
			->setSelect(['ID'])
			->where('CHAT_ID', $chatId)
			->where('ID', '<=', $lastMessageId)
			->setLimit(1)
			->fetch() !== false
		;
	}

	private function isAlreadyCleanedForAll(int $chatId, int $newStartId): bool
	{
		return RelationTable::query()
			->setSelect(['ID'])
			->where('CHAT_ID', $chatId)
			->where('START_ID', '<', $newStartId)
			->setLimit(1)
			->fetch() === false
		;
	}

	/**
	 * Written as one conditional update instead of a save() of the relation read above: the border, the
	 * read cursor and the unread state of the row all move on their own - a message arriving, a read in
	 * another tab, a second cleanup of the same chat - so a write built out of the values this request
	 * happens to hold would carry them backwards and bring messages already hidden back into the chat.
	 * The same shape the walk over the members of a cleanup for everyone uses
	 * ({@see ChatContentCollector::moveRelationsAboveBorder()}). The file border goes with it: the legacy
	 * file lists read the folder of the chat by LAST_FILE_ID alone, and a cleanup for oneself never
	 * deletes a file, so without it those lists would keep showing the cleared attachments for good.
	 */
	protected function moveSelfRelationAboveBorder(int $userId, int $lastMessageId): void
	{
		$chatId = $this->chat->getId();
		$lastFileId = ChatContentCollector::findLastFileIdBehindBorder($chatId, $lastMessageId);

		RelationTable::updateByFilter(
			[
				'=CHAT_ID' => $chatId,
				'=USER_ID' => $userId,
				'<START_ID' => $lastMessageId + 1,
			],
			[
				'START_ID' => $lastMessageId + 1,
				'LAST_ID' => new SqlExpression(
					'CASE WHEN ?# > ?i THEN ?# ELSE ?i END',
					'LAST_ID', $lastMessageId, 'LAST_ID', $lastMessageId
				),
				// Unread state that starts above the border belongs to messages that stay visible, so it
				// survives the cleanup.
				'COUNTER' => new SqlExpression(
					'CASE WHEN ?# <= ?i THEN 0 ELSE ?# END',
					'UNREAD_ID', $lastMessageId, 'COUNTER'
				),
				'UNREAD_ID' => new SqlExpression(
					'CASE WHEN ?# <= ?i THEN 0 ELSE ?# END',
					'UNREAD_ID', $lastMessageId, 'UNREAD_ID'
				),
				...ChatContentCollector::lastFileIdFields($lastFileId),
			]
		);

		// The update went past the Relation object this hit is holding, so both the object and the
		// border cached beside it have to go.
		$this->chat->getRelationFacade()?->cleanCache();
		RelationCollection::clearStartIdCache($chatId, $userId);
	}

	/**
	 * The highest border a cleanup for oneself has drawn in this chat, kept on the chat for the deliveries
	 * that need to know whether anybody hides a message at all ({@see Chat::getSelfHistoryStartId()}).
	 * Raised the way the border of the chat is, under the same lock and ahead of the relation: two
	 * cleanups for oneself racing here unserialized could leave the lower of two borders, or two rows of
	 * it with no say over which one a reader keeps, and a post hidden from a reader would push them its
	 * comments for good - the next cleanup finds the relation already standing above the border and
	 * moves nothing. A cleanup that cannot get the lock, or cannot write, stops before it moves anything
	 * ({@see self::storeBorder()}).
	 */
	private function raiseSelfHistoryBorder(int $newStartId): Result
	{
		return $this->storeBorder(Params::SELF_HISTORY_START_ID, $newStartId);
	}

	/**
	 * Bounded on the hit the same way the walk over the members is: a reader of an ordinary channel is
	 * unsubscribed before the answer, while a reader of a channel with more comment chats than the
	 * budget covers hands the rest to a scheduled walk of the same shape. The walk has to be finished
	 * one way or another - a subscription left behind keeps telling the user about comments on a post
	 * they can no longer see - and nothing else would ever come back for it.
	 */
	private function unsubscribeFromCommentChats(int $userId, int $lastMessageId): void
	{
		$cleanup = new CommentSubscriptionCleanupAgent($this->chat->getId(), $userId, $lastMessageId);

		$isFinished = $cleanup->unsubscribeWithinBudget(
			self::readHitBatchBudget(),
			microtime(true) + ChatHistoryCleanupAgent::readHitTimeLimit()
		);

		if (!$isFinished)
		{
			$cleanup->register();
		}
	}

	private function updateRecentForSelf(int $userId, int $lastMessageId): void
	{
		if ($this->chat instanceof Chat\PrivateChat)
		{
			$this->deletePrivateRecent($this->chat, $userId, $lastMessageId);

			return;
		}

		RecentTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=ITEM_CID' => $this->chat->getId(),
			'<=ITEM_MID' => $lastMessageId,
		]);
	}

	private function deletePrivateRecent(Chat\PrivateChat $chat, int $userId, int $lastMessageId): void
	{
		$companionId = $chat->getCompanion($userId)?->getId();
		if (!$companionId)
		{
			return;
		}

		RecentTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=ITEM_TYPE' => Chat::IM_TYPE_PRIVATE,
			'=ITEM_ID' => $companionId,
			'<=ITEM_MID' => $lastMessageId,
		]);
	}

	/**
	 * Bounded by lastMessageId for the same reason as the "for all" branch: a message arriving during the
	 * cleanup stays visible (it is above the new START_ID), so its unread state and views must survive.
	 * Runs after the recent row is gone, as in the "for all" branch: the counters cache is dropped here,
	 * and a cache rebuilt while the row is still there would keep stale recentSections.
	 */
	private function clearCountersAndViewsForSelf(int $chatId, int $userId, int $lastMessageId): void
	{
		ServiceLocator::getInstance()
			->get(CountersUpdater::class)
			->delete()
			->byChatToMessage($chatId, $lastMessageId)
			->forUser($userId)
			->execute()
		;

		MessageViewedTable::deleteByFilter([
			'=CHAT_ID' => $chatId,
			'=USER_ID' => $userId,
			'<=MESSAGE_ID' => $lastMessageId,
		]);
	}

	/**
	 * Pointers to mentions and reactions are the user's own rows, so they leave with the history the
	 * same way their counters and views do. The "for all" branch drops them in the scheduled cleanup,
	 * where the whole chat is walked in batches.
	 */
	private function deleteAnchorsForSelf(int $chatId, int $userId, int $lastMessageId): void
	{
		AnchorTable::deleteByFilter([
			'=CHAT_ID' => $chatId,
			'=USER_ID' => $userId,
			'<=MESSAGE_ID' => $lastMessageId,
		]);
	}

	/**
	 * The chat has just left the user's recent, so a parent collab row still pointing at it would preview
	 * a message that is now behind the user's history border.
	 */
	private function recomputeCollabPreviewSourceForSelf(int $userId): void
	{
		if (!$this->chat->getParentChat() instanceof Chat\CollabChat)
		{
			return;
		}

		ServiceLocator::getInstance()
			->get(CollabPreviewSourcePointerService::class)
			->recomputeForRemovedSourceChat($this->chat, $userId)
		;
	}

	/**
	 * Members' preview pointers may reference a message of this chat, and the scheduled cleanup removes
	 * that content for good. Only the rows that really point behind the border are touched, and only one
	 * batch of them: a collab the size of the portal must not be fanned out on the hit, so the rest is
	 * left to the step of the same name in the scheduled cleanup.
	 */
	private function recomputeCollabPreviewSourceForAll(int $lastMessageId): void
	{
		$parent = $this->chat->getParentChat();
		if (!$parent instanceof Chat\CollabChat)
		{
			return;
		}

		ServiceLocator::getInstance()
			->get(CollabPreviewSourcePointerService::class)
			->recomputeForClearedSource(
				(int)$parent->getChatId(),
				$this->chat->getId(),
				$lastMessageId,
				ChatHistoryCleanupAgent::readCleanupLimit()
			)
		;
	}

	/**
	 * Written at once instead of behind a background job, and ahead of the move: it is the only thing that
	 * ever tells an offline client to drop the copy it holds, and nothing but another cleanup of the same
	 * chat would ever write it. A row written for a move that then fails costs a client one reload, the
	 * other order costs it the history it should not keep.
	 */
	private function logSyncForSelf(int $userId): void
	{
		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::DELETE_HISTORY_FOR_SELF_EVENT, Sync\Event::CHAT_CLEANUP_ENTITY, $this->chat->getId()),
			$userId,
			$this->chat,
			deferred: false,
		);
	}

	/**
	 * An open chat is readable without a relation, so its cleanup is journalled once, as a global row
	 * every client reads: a reader who never joined has no row of their own, and a member of an open
	 * chat is let in by the same access check the global row is filtered by. A closed chat has no
	 * readers beyond its members, and their rows are written by the walk, batch by batch
	 * ({@see ChatContentCollector::notifyMembersOfCleanup()}).
	 */
	private function logSyncForAll(): void
	{
		if (!$this->chat->getChatType()->isOpen)
		{
			return;
		}

		Sync\Logger::getInstance()->addGlobal(
			new Sync\Event(Sync\Event::DELETE_HISTORY_FOR_ALL_EVENT, Sync\Event::CHAT_CLEANUP_ENTITY, $this->chat->getId()),
			false,
			$this->chat
		);
	}
}
