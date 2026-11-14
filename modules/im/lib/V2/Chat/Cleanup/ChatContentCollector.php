<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cleanup;

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\SystemUser;
use Bitrix\Im\Model\AnchorTable;
use Bitrix\Im\Model\BlockUserTable;
use Bitrix\Im\Model\ChatIndexTable;
use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\Model\LastMessageTable;
use Bitrix\Im\Model\LinkCalendarIndexTable;
use Bitrix\Im\Model\LinkCalendarTable;
use Bitrix\Im\Model\LinkFavoriteTable;
use Bitrix\Im\Model\LinkFileTable;
use Bitrix\Im\Model\LinkPinPersonalTable;
use Bitrix\Im\Model\LinkPinTable;
use Bitrix\Im\Model\LinkReminderTable;
use Bitrix\Im\Model\LinkTaskTable;
use Bitrix\Im\Model\LinkUrlIndexTable;
use Bitrix\Im\Model\LinkUrlTable;
use Bitrix\Im\Model\MessageDisappearingTable;
use Bitrix\Im\Model\MessageIndexTable;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\MessageUuidTable;
use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\Im\Model\NoRelationPermissionDiskTable;
use Bitrix\Im\Model\ReactionTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\Model\SharingLinkMemberTable;
use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Pull\Event\ChatHistoryDeletedForAll;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Im\V2\Link\File\FileService;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePointerService;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Sync\Event;
use Bitrix\Im\V2\Sync\Logger;
use Bitrix\ImConnector\Model\ChatLastMessageTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use CPullStack;

class ChatContentCollector
{
	/**
	 * Comment chats per pass. A chat costs a whole teardown, not a deleted row, so the row limit of
	 * the pass is not a usable portion here.
	 */
	private const COMMENT_CHATS_PER_PASS = 50;

	/**
	 * Row batches a pass may delete while taking those chats apart. The number of chats says nothing
	 * about how much content is inside them, so the teardown carries a cap of its own.
	 */
	private const COMMENT_CONTENT_BATCHES_PER_PASS = 20;

	/**
	 * Disk files per pass. A file costs a Disk deletion, not a deleted row, so the row limit of the
	 * pass is not a usable portion here either.
	 */
	private const FILES_PER_PASS = 100;

	private int $chatId;
	private ?int $messageIdBoundary;
	private array $chatMembers;

	/** The moment a walk gives the pass back, whoever is paying for it. */
	private ?float $deadline = null;

	/** The last USER_ID the recompute of the collab preview has passed. */
	private int $collabPreviewCursor = 0;

	/** The last USER_ID the walk over the members has moved above the border. */
	private int $membersCursor = 0;
	private ?int $lastFileIdBehindBorder = null;

	public function __construct(Chat|int $chat, ?int $messageIdBoundary = null)
	{
		if ($chat instanceof Chat)
		{
			$this->chatId = $chat->getChatId();
		}
		else
		{
			$this->chatId = $chat;
		}

		$this->messageIdBoundary = $messageIdBoundary;
	}

	public function withMembersCursor(int $membersCursor): self
	{
		$this->membersCursor = max(0, $membersCursor);

		return $this;
	}

	public function withCollabPreviewCursor(int $collabPreviewCursor): self
	{
		$this->collabPreviewCursor = max(0, $collabPreviewCursor);

		return $this;
	}

	/**
	 * The moment the caller stops paying for this walk. A step nested inside another one carries a
	 * budget of batches of its own, and a batch of it costs a whole chat: without the deadline of the
	 * caller the outer loop only ever measures its time once that nested budget is spent.
	 */
	public function withDeadline(?float $deadline): self
	{
		$this->deadline = $deadline;

		return $this;
	}

	private function isDeadlineReached(): bool
	{
		return $this->deadline !== null && microtime(true) >= $this->deadline;
	}

	public function getMembersCursor(): int
	{
		return $this->membersCursor;
	}

	public function getCollabPreviewCursor(): int
	{
		return $this->collabPreviewCursor;
	}

	private function isHistoryCleanup(): bool
	{
		return $this->messageIdBoundary !== null;
	}

	private function getMessageBoundaryFilter(string $columnName = 'MESSAGE_ID'): array
	{
		if (!$this->isHistoryCleanup())
		{
			return [];
		}

		return ['<=' . $columnName => $this->messageIdBoundary];
	}

	/**
	 * The link rows of the cleared messages, a batch per call. Two kinds of rows of the chat are left
	 * out, and both are left out because the file behind them is not history the cleanup takes away.
	 *
	 * A row with MESSAGE_ID = 0 stands below every border there can be, while the file behind it is not
	 * history: nothing points at it from the past. Deleting it would leave the message that names it later
	 * with an attachment already gone. No write path produces such a row today - every insert goes through
	 * FileCollection::save() with a message already saved, and the two-phase upload registers its
	 * placeholder message before the file ({@see \CIMDisk::UploadFileRegister()}). The guard is here because
	 * the deletion runs with system rights and cannot be taken back, and because the revocation contour
	 * reads the same rows with the opposite rule ({@see \Bitrix\Im\V2\Link\File\FileService::isOwnerChatCleared()});
	 * one of the two would have to be wrong.
	 *
	 * The rows of a file this chat still shows - by a message above the border, or by such an upload -
	 * stay as well, cleared message or not. The file itself stays for the message showing it, and the
	 * row of the cleared message is then the only durable proof that the file was part of the cleared
	 * history: the signed url given out for that message is closed by the oldest row of the chat
	 * ({@see \Bitrix\Im\V2\Link\File\FileService::isBehindChatHistoryBorder()}), and taking the row away
	 * would open the url again the moment the files step reached the file. No reader ever sees such a
	 * row - every list of the chat starts at the border - and a chat deleted for good takes it along with
	 * the rest. A file the chat shows through the FILE_ID param alone cannot be among them: a message
	 * above the border was sent after the cleanup began, and every message sent since the rows exist
	 * has one.
	 *
	 * The full deletion has no border and selects every row of the chat, the folder holding the files
	 * included.
	 *
	 * @return array<int, array{ID: int, DISK_FILE_ID: int}> keyed by the row id
	 */
	private function collectClearedFileRows(int $limit): array
	{
		$query = LinkFileTable::query()
			->setSelect(['ID', 'DISK_FILE_ID'])
			->where('CHAT_ID', $this->chatId)
			->setLimit($limit)
		;

		if ($this->isHistoryCleanup())
		{
			$query
				->where('MESSAGE_ID', '>', 0)
				->where('MESSAGE_ID', '<=', $this->messageIdBoundary)
				->registerRuntimeField(new Reference(
					'SHOWN_BY',
					LinkFileTable::class,
					Join::on('this.DISK_FILE_ID', 'ref.DISK_FILE_ID')
						->where('ref.CHAT_ID', $this->chatId)
						->where(
							Query::filter()
								->logic('or')
								->where('ref.MESSAGE_ID', '>', $this->messageIdBoundary)
								->where('ref.MESSAGE_ID', 0)
						)
				))
				->whereNull('SHOWN_BY.ID')
			;
		}

		$rows = [];
		foreach ($query->fetchAll() as $row)
		{
			$rows[(int)$row['ID']] = ['ID' => (int)$row['ID'], 'DISK_FILE_ID' => (int)$row['DISK_FILE_ID']];
		}

		return $rows;
	}

	/**
	 * @param string $tableClass
	 * @param mixed $columnValue
	 * @param int|null $limit
	 * @param string $columnName
	 * @param string|array $selectColumnName
	 * @param array $additionalFilter
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function collectByColumn(
		string $tableClass,
		mixed $columnValue,
		int|null $limit = null,
		string $columnName = 'CHAT_ID',
		string|array $selectColumnName = 'ID',
		array $additionalFilter = [],
	): array
	{
		if (!is_a($tableClass, DataManager::class, true))
		{
			return [];
		}

		$isMultiColumn = is_array($selectColumnName);
		$indexColumn = $isMultiColumn ? reset($selectColumnName) : $selectColumnName;

		$filter = [(is_array($columnValue) ? '@' : '=') . $columnName => $columnValue];
		$filter = array_merge($filter, $additionalFilter);

		$params = [
			'select' => $isMultiColumn ? $selectColumnName : [$selectColumnName],
			'filter' => $filter,
		];

		if (null !== $limit)
		{
			$params['limit'] = $limit;
		}

		$result = $tableClass::getList($params);
		$data = [];

		foreach ($result->fetchAll() as $row)
		{
			$data[$row[$indexColumn]] = $isMultiColumn ? $row : $row[$indexColumn];
		}

		return $data;
	}

	/**
	 * @param string $tableClass
	 * @param array $idColumnValues
	 * @param string $idColumnName
	 * @param array $additionalFilter narrows the deletion to a scope of its own, for the tables where the
	 *                               id column alone names rows of somebody else as well
	 * @return int rows deleted
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function deleteByColumn(
		string $tableClass,
		array $idColumnValues,
		string $idColumnName = 'ID',
		array $additionalFilter = [],
	): int
	{
		if (0 === count($idColumnValues))
		{
			return 0;
		}

		if (!is_a($tableClass, DataManager::class, true))
		{
			return 0;
		}

		$filter = array_merge(['@' . $idColumnName => $idColumnValues], $additionalFilter);
		$whereSql = Query::buildFilterSql($tableClass::getEntity(), $filter);
		$tableName = $tableClass::getTableName();
		$connection = Application::getConnection();
		$connection->queryExecute("DELETE FROM {$tableName} WHERE {$whereSql}");

		return (int)$connection->getAffectedRowsCount();
	}

	private function getCleanupSequence(): array
	{
		if ($this->isHistoryCleanup())
		{
			// The members go first: the hit walks them up to its budget and leaves the rest to the
			// first pass. The preview pointers of a parent collab follow them, because they are
			// resolved against the relations the walk has just moved. The files come next: a message
			// behind the border is unreachable at once, while its file stays open through the rights
			// of the chat folder until the file itself is gone. The anchors have a step of their own
			// and it stands ahead of the messages: their number is not bounded by the messages they
			// point at, and every owner of one costs an invalidation of their cache
			// ({@see self::cleanupAnchors()}). The full deletion needs none of it - it drops the
			// anchors by CHAT_ID in cleanupChatBasics(). The fields of the chat
			// close the sequence: the hit writes them as soon as the history is hidden, and a hit that
			// died before it got there leaves them to this step ({@see self::updateChatFields()}).
			return [
				'cleanupMembers',
				'cleanupCollabPreview',
				'cleanupFiles',
				'cleanupCommentChats',
				'cleanupAnchors',
				'cleanupMessages',
				'cleanupLastMessages',
				'cleanupFavoritesLinks',
				'cleanupUrlsLinks',
				'cleanupTasksLinks',
				'cleanupCalendarLinks',
				'cleanupFilesLinks',
				'cleanupReactions',
				'cleanupReminder',
				'cleanupChatFields',
			];
		}

		return [
			'cleanupChatAddons',
			'cleanupMessages',
			'cleanupLastMessages',
			'cleanupFavoritesLinks',
			'cleanupUrlsLinks',
			'cleanupTasksLinks',
			'cleanupCalendarLinks',
			'cleanupFilesLinks',
			'cleanupReactions',
			'cleanupReminder',
			'cleanupNoRelationPermDisk',
		];
	}

	private function getCurrentCleanupStep(?string $previousCompletedStep = null): ?string
	{
		$prevStepFound = false;

		foreach ($this->getCleanupSequence() as $step)
		{
			if ((null === $previousCompletedStep) || $prevStepFound)
			{
				return $step;
			}

			if ($step === $previousCompletedStep)
			{
				$prevStepFound = true;
			}
		}

		return null;
	}

	public function processCleanup(int $limit, ?string $previousCompletedStep = null): CleanupProgress
	{
		$currentStep = $this->getCurrentCleanupStep($previousCompletedStep);

		if (null === $currentStep)
		{
			return CleanupProgress::finished();
		}

		$isStepCompleted = !$this->{$currentStep}($limit);

		return CleanupProgress::inProgress($isStepCompleted ? $currentStep : $previousCompletedStep);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getNextChatIdToCleanup(): ?int
	{
		$nextChildChat = $this->collectByColumn(
			ChatTable::class,
			$this->chatId,
			1,
			'PARENT_ID',
		);

		if (count($nextChildChat) > 0)
		{
			return array_key_first($nextChildChat);
		}

		return null;
	}

	/**
	 * Does critical cleanup using DELETE queries without loops and only by indexed fields. Must be run on
	 * the same hit when user initiates chat deletion. All other operations will be performed via agents.
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function deleteChat(?int $userId = null): void
	{
		$this->cleanupChatBasics($userId);
		ChatCleanupAgent::register($this->chatId, userId: $userId);
	}

	protected function getChatMembers(): array
	{
		$this->chatMembers ??= $this->collectByColumn(
			RelationTable::class,
			$this->chatId,
			selectColumnName: 'USER_ID',
		);

		return $this->chatMembers;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function writeSyncLog(): void
	{
		$userIds = $this->getChatMembers();
		$chat = Chat::getInstance($this->chatId);
		$logger = Logger::getInstance();

		foreach ($userIds as $userId)
		{
			$logger->add(
				new Event(Event::COMPLETE_DELETE_EVENT, Event::CHAT_ENTITY, $this->chatId),
				(int)$userId,
				$chat,
			);
		}
	}

	/**
	 * @param int|null $userId
	 * @param bool $broadcastDeletePush chatDelete reaches every connected user of the portal, so a path
	 *                                  that takes chats down in bulk addresses their members instead
	 * @return void
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function cleanupChatBasics(?int $userId = null, bool $broadcastDeletePush = true): void
	{
		$chat = Chat::getInstance($this->chatId);
		$deletePush = $this->buildDeletePush($chat, $userId);
		// Read while the relations are still there: the addressed push has no other list of recipients.
		$deletePushRecipients = $broadcastDeletePush ? [] : $this->getChatMembers();

		// Capture before deletion: members' preview pointers may reference a message in this soon-to-be-deleted source.
		$collabParentToRecompute = $this->getCollabParentIdForPreviewSource($chat);

		$this->writeSyncLog();
		$this->deleteByColumn(RelationTable::class, [$this->chatId], 'CHAT_ID');
		// Keyed by ENTITY_TYPE + ENTITY_ID; the type filter keeps same-id non-chat rows intact.
		$chatEntityFilter = [
			'@ENTITY_TYPE' => LinkEntityType::chatTypeValues(),
			'=ENTITY_ID' => (string)$this->chatId,
		];
		SharingLinkMemberTable::deleteByFilter($chatEntityFilter);
		SharingLinkTable::deleteByFilter($chatEntityFilter);
		$this->deleteByColumn(ChatTable::class, [$this->chatId]);
		Chat::cleanCache($this->chatId);
		$this->deleteByColumn(RecentTable::class, [$this->chatId], 'ITEM_CID');
		$this->deleteByColumn(FolderChatTable::class, [$this->chatId], 'CHAT_ID');
		$this->deleteByColumn(FolderPinTable::class, [$this->chatId], 'CHAT_ID');
		$this->deleteByColumn(MessageUnreadTable::class, [$this->chatId], 'CHAT_ID');
		$this->deleteByColumn(MessageUnreadTable::class, [$this->chatId], 'PARENT_ID');
		$this->cleanupCounterCache();
		$this->deleteByColumn(ChatIndexTable::class, [$this->chatId], 'CHAT_ID');
		$this->deleteByColumn(AnchorTable::class, [$this->chatId], 'CHAT_ID');
		$this->cleanupAnchorCache($this->getChatMembers());

		(new Structure($chat))->unlinkAll();

		// Recompute after the source is gone, so the resolver no longer treats it as a candidate.
		if ($collabParentToRecompute !== null)
		{
			ServiceLocator::getInstance()
				->get(CollabPreviewSourcePointerService::class)
				->recomputeForCollabMembers($collabParentToRecompute)
			;
		}

		if ($broadcastDeletePush)
		{
			CPullStack::AddShared($deletePush);
		}
		else
		{
			$this->sendDeletePush($deletePushRecipients, $deletePush);
		}

		$chat->onAfterDelete();
	}

	/**
	 * @param int|null $userId the user the deletion is attributed to, when a hit stands behind it
	 */
	private function buildDeletePush(Chat $chat, ?int $userId): array
	{
		return [
			'module_id' => 'im',
			'command' => 'chatDelete',
			'params' => [
				'chatId' => $this->chatId,
				'parentChatId' => $chat->getParentChatId() ?? 0,
				'dialogId' => $chat->getDialogId(),
				'type' => $chat->getExtendedType(),
				'userId' => $userId,
			],
		];
	}

	/**
	 * @param array $userIds the recipients of the push, empty meaning nobody is left to tell
	 */
	private function sendDeletePush(array $userIds, array $deletePush): void
	{
		if ($userIds === [] || !Loader::includeModule('pull'))
		{
			return;
		}

		\Bitrix\Pull\Event::add($userIds, $deletePush);
	}

	/**
	 * Returns the parent only when it is a collab. The collab itself is excluded: deleting it removes
	 * its own recent rows, so there is no pointer left to keep consistent.
	 */
	private function getCollabParentIdForPreviewSource(Chat $chat): ?int
	{
		$parent = $chat->getParentChat();

		return $parent instanceof Chat\CollabChat ? (int)$parent->getChatId() : null;
	}

	protected function cleanupCounterCache(): void
	{
		foreach ($this->getChatMembers() as $userId)
		{
			CounterService::clearCache((int)$userId);
		}
	}

	/**
	 * @param array $userIds the users whose anchors have just left the table
	 */
	protected function cleanupAnchorCache(array $userIds): void
	{
		AnchorContainer::getInstance()->getAnchorProvider()->cleanUsersCache(array_map('intval', $userIds));
	}

	/**
	 * @param $limit
	 * @return bool
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	protected function cleanupChatAddons($limit): bool
	{
		$this->deleteByColumn(ChatParamTable::class, [$this->chatId], 'CHAT_ID');
		// The rows go through raw SQL, so the file cache of the parameters is not dropped on its own.
		Params::cleanCache($this->chatId);
		$this->deleteByColumn(ChatLastMessageTable::class, [$this->chatId], 'EXTERNAL_CHAT_ID');
		$this->deleteByColumn(BlockUserTable::class, [$this->chatId], 'CHAT_ID');

		return false;
	}

	/**
	 * Runs the member walk on its own, up to a budget of batches, for the hit that hides the history:
	 * an ordinary chat is done before the answer, while the members of a chat larger than the budget
	 * are left to the scheduled cleanup, whose first step is the same walk.
	 *
	 * @return bool whether the walk has reached the last member
	 */
	public function cleanupMembersWithinBudget(int $limit, int $batchBudget, ?float $deadline = null): bool
	{
		$this->withDeadline($deadline);

		for ($batch = 0; $batch < $batchBudget; $batch++)
		{
			if (!$this->cleanupMembers($limit))
			{
				return true;
			}

			// A batch always runs to its end: the rows it has moved are what the next walk resumes from.
			if ($this->isDeadlineReached())
			{
				return false;
			}
		}

		return $this->collectMembersBehindBorder(1) === [];
	}

	/**
	 * The members whose relations still start below the border, a batch per call. Their unread rows go
	 * first, their recent rows next and the relations last: a walk that dies inside a batch leaves the
	 * batch to the same selection, which finds it again by the relations it has not moved yet - it
	 * resumes from the last cursor it saved, and those rows are still ahead of it. The rows already
	 * dropped are dropped for good. The cursor itself is what keeps every batch reading forward only:
	 * without it the filter by START_ID scans the members already moved over and over. The views of the
	 * removed messages are not here at all: they go with the messages, by MESSAGE_ID, and nobody can
	 * reach a message behind the border meanwhile.
	 *
	 * Telling the batch comes before moving it, because moving it is what takes it out of the selection:
	 * a notification that fails afterwards has no second chance, while one repeated on the next pass
	 * costs nothing - the push carries the same border and the journal upserts the same row. The chat
	 * itself already stands behind the border by then ({@see Chat::getStartId()} takes the greater of the
	 * relation and the chat), so a client answering the push reads the cleaned history, not the old one.
	 *
	 * The unread rows go before the notification for the same reason the notification goes before the
	 * move: the push carries the counter the batch is left with, and a counter read ahead of the delete
	 * would be the one the client already has.
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupMembers(int $limit): bool
	{
		$userIds = $this->collectMembersBehindBorder($limit);

		if (count($userIds) === 0)
		{
			return false;
		}

		// The cache of the counters goes with the rows, and only for the members who had any.
		ServiceLocator::getInstance()
			->get(CountersUpdater::class)
			->delete()
			->byChatToMessage($this->chatId, $this->messageIdBoundary)
			->forUsers($userIds)
			->execute()
		;
		$this->deleteRecentOfMembers($userIds);
		$this->notifyMembersOfCleanup($userIds);
		$this->moveRelationsAboveBorder($userIds);
		$this->membersCursor = (int)end($userIds);

		return true;
	}

	/**
	 * The push and the journal go to the batch the walk has just moved, not to the chat as a whole: the
	 * hit reaches a bounded number of members, and the members beyond it hear about the border when the
	 * scheduled walk comes for them. An open chat writes no rows here - its cleanup is journalled once,
	 * as a global row that a reader without a relation sees as well
	 * ({@see ChatCleanupService::logSyncForAll()}).
	 *
	 * Called while the batch is still selectable, so a failure here leaves it to the next pass rather
	 * than to nobody ({@see self::cleanupMembers()}).
	 *
	 * The push carries the counter each member of the batch is left with: the cleanup lowers the counter
	 * instead of clearing it, because the messages that arrived above the border stay unread, and no
	 * other event of this walk tells the client the new value.
	 *
	 * @param int[] $userIds
	 */
	protected function notifyMembersOfCleanup(array $userIds): void
	{
		$chat = Chat::getInstance($this->chatId);

		(new ChatHistoryDeletedForAll(
			$chat,
			$this->messageIdBoundary,
			$userIds,
			$this->getCountersOfBatch($userIds),
		))->send();

		if ($chat->getChatType()->isOpen)
		{
			return;
		}

		// Written now, not behind a background job at the end of the hit: the move that follows takes
		// the batch out of the selection for good, so a row still owed when the process dies is a row
		// nobody comes back for - and it is the only thing that ever tells an offline client to drop
		// the copy it holds.
		Logger::getInstance()->add(
			new Event(Event::DELETE_HISTORY_FOR_ALL_EVENT, Event::CHAT_CLEANUP_ENTITY, $this->chatId),
			$userIds,
			$chat,
			deferred: false,
		);
	}

	/**
	 * One grouped count over the rows the batch has left, read and not tracked: the overflow-tracking
	 * variant of the service would write back the very overflow rows the delete of this batch has just
	 * dropped, while the push needs the value alone.
	 *
	 * @param int[] $userIds
	 */
	private function getCountersOfBatch(array $userIds): UsersCounterMap
	{
		return ServiceLocator::getInstance()
			->get(CountersProvider::class)
			->getForUsers($this->chatId, $userIds)
		;
	}

	/**
	 * The range by USER_ID is what IX_IM_REL_6 (CHAT_ID, USER_ID) covers, ordering included; the border
	 * is checked on the rows it returns, not searched for.
	 *
	 * @return int[]
	 */
	private function collectMembersBehindBorder(int $limit): array
	{
		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $this->chatId)
			->where('USER_ID', '>', $this->membersCursor)
			->where('START_ID', '<', $this->messageIdBoundary + 1)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
	}

	/**
	 * The rows of the parent collab that still preview a message of this chat from behind the border, a
	 * batch per call. A chat whose parent is not a collab has none of them, so the step is done at once.
	 * The cursor of the walk outlives the pass, carried on by the name of the agent the way the cursor
	 * of the member walk is: the recomputed rows leave the selection but stay in the index it reads
	 * ({@see CollabPreviewSourcePointerService::recomputeForClearedSource()}), so a pass starting over
	 * would read every member it has already handled.
	 */
	protected function cleanupCollabPreview(int $limit): bool
	{
		$parent = Chat::getInstance($this->chatId)->getParentChat();

		if (!$parent instanceof Chat\CollabChat)
		{
			return false;
		}

		return ServiceLocator::getInstance()
			->get(CollabPreviewSourcePointerService::class)
			->recomputeForClearedSource(
				(int)$parent->getChatId(),
				$this->chatId,
				$this->messageIdBoundary,
				$limit,
				$this->collabPreviewCursor
			)
		;
	}

	/**
	 * A private chat keeps its recent rows under the companion id, not under the chat id, so the
	 * generic ITEM_CID deletion would miss them.
	 *
	 * @param int[] $userIds
	 */
	private function deleteRecentOfMembers(array $userIds): void
	{
		$chat = Chat::getInstance($this->chatId);

		if ($chat instanceof Chat\PrivateChat)
		{
			foreach ($userIds as $userId)
			{
				$companionId = $chat->getCompanion($userId)?->getId();
				if (!$companionId)
				{
					continue;
				}

				RecentTable::deleteByFilter([
					'=USER_ID' => $userId,
					'=ITEM_TYPE' => Chat::IM_TYPE_PRIVATE,
					'=ITEM_ID' => $companionId,
					'<=ITEM_MID' => $this->messageIdBoundary,
				]);
			}

			return;
		}

		RecentTable::deleteByFilter([
			'=ITEM_CID' => $this->chatId,
			'@USER_ID' => $userIds,
			'<=ITEM_MID' => $this->messageIdBoundary,
		]);
	}

	/**
	 * The unread state that starts above the border belongs to messages that stay visible, so it
	 * survives; LAST_ID is raised to the border, so nothing behind it counts as unread any more.
	 *
	 * @param int[] $userIds
	 */
	private function moveRelationsAboveBorder(array $userIds): void
	{
		$lastMessageId = $this->messageIdBoundary;

		RelationTable::updateByFilter(
			[
				'=CHAT_ID' => $this->chatId,
				'@USER_ID' => $userIds,
				'<START_ID' => $lastMessageId + 1,
			],
			[
				'START_ID' => $lastMessageId + 1,
				'LAST_ID' => new SqlExpression(
					'CASE WHEN ?# > ?i THEN ?# ELSE ?i END',
					'LAST_ID', $lastMessageId, 'LAST_ID', $lastMessageId
				),
				'COUNTER' => new SqlExpression(
					'CASE WHEN ?# <= ?i THEN 0 ELSE ?# END',
					'UNREAD_ID', $lastMessageId, 'COUNTER'
				),
				'UNREAD_ID' => new SqlExpression(
					'CASE WHEN ?# <= ?i THEN 0 ELSE ?# END',
					'UNREAD_ID', $lastMessageId, 'UNREAD_ID'
				),
				...self::lastFileIdFields($this->getLastFileIdBehindBorder()),
			]
		);

		// The bulk update went past the Relation objects loaded before it. Dropping the START_ID cache
		// is not enough on its own: a stale object added back to a collection refills it with the old
		// border.
		Chat::getInstance($this->chatId)->getRelationFacade()?->cleanCache();
		RelationCollection::clearStartIdCache($this->chatId);
	}

	/**
	 * Read once per process: every batch of the walk moves its relations past the same border, and the
	 * rows the answer comes from only leave with the files step, which never runs ahead of the walk.
	 */
	private function getLastFileIdBehindBorder(): int
	{
		return $this->lastFileIdBehindBorder ??= self::findLastFileIdBehindBorder($this->chatId, $this->messageIdBoundary);
	}

	/**
	 * The border of the legacy file lists, which read the folder of the chat by disk file id rather than
	 * the messages by START_ID ({@see \CIMDisk::GetHistoryFiles()}): the highest Disk file a message at
	 * or below the border still shows. A file uploaded for a message that has not been sent yet has no
	 * message to fall behind, and a message above the border normally carries a younger file than
	 * anything behind it - normally, because a file is attached by id as well, and an old file attached
	 * to a message above the border is younger than nothing. A single threshold cannot both hide the
	 * cleared files and keep that one, and of the two it is the attachment of a surviving message that
	 * has to stay: the border stops right below the oldest file a message above it shows, whatever is
	 * cleared past that point. Only the rows of this chat count - a file attached from another chat by id
	 * is the concern of the chat that owns it. Both selections are answered from
	 * IX_B_IM_LINK_FILE_7 (CHAT_ID, MESSAGE_ID, DISK_FILE_ID) without a row read.
	 */
	public static function findLastFileIdBehindBorder(int $chatId, int $lastMessageId): int
	{
		$row = LinkFileTable::query()
			->registerRuntimeField(new ExpressionField('LAST_FILE_ID', 'MAX(%s)', ['DISK_FILE_ID']))
			->setSelect(['LAST_FILE_ID'])
			->where('CHAT_ID', $chatId)
			->where('MESSAGE_ID', '>', 0)
			->where('MESSAGE_ID', '<=', $lastMessageId)
			->fetch()
		;

		$lastFileId = (int)($row['LAST_FILE_ID'] ?? 0);

		if ($lastFileId <= 0)
		{
			return 0;
		}

		$row = LinkFileTable::query()
			->registerRuntimeField(new ExpressionField('FIRST_KEPT_FILE_ID', 'MIN(%s)', ['DISK_FILE_ID']))
			->setSelect(['FIRST_KEPT_FILE_ID'])
			->where('CHAT_ID', $chatId)
			->where('MESSAGE_ID', '>', $lastMessageId)
			->fetch()
		;

		$firstKeptFileId = (int)($row['FIRST_KEPT_FILE_ID'] ?? 0);

		if ($firstKeptFileId > 0 && $firstKeptFileId <= $lastFileId)
		{
			return $firstKeptFileId - 1;
		}

		return $lastFileId;
	}

	/**
	 * The relation keeps the higher of its own value and the border: a member who has cleared the chat
	 * for themselves past this cleanup already stands further than it reaches.
	 *
	 * @return array<string, SqlExpression>
	 */
	public static function lastFileIdFields(int $lastFileId): array
	{
		if ($lastFileId <= 0)
		{
			return [];
		}

		return [
			'LAST_FILE_ID' => new SqlExpression(
				'CASE WHEN ?# < ?i THEN ?i ELSE ?# END',
				'LAST_FILE_ID', $lastFileId, $lastFileId, 'LAST_FILE_ID'
			),
		];
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupLastMessages(int $limit): bool
	{
		$ids = $this->collectByColumn(
			LastMessageTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LastMessageTable::class, $ids);

		return true;
	}

	/**
	 * Comment chats of the posts left behind the border. Deleting a post takes its comment chat down
	 * with it ({@see \Bitrix\Im\V2\Message\Delete\Strategy\CompleteDeletionStrategy::deleteLinks()}),
	 * and a post removed with the history is no more reachable than a deleted one: its comments, their
	 * files and their rows in b_im_message_unread (denormalised to the channel through PARENT_ID) would
	 * stay in the database with no way to open them. The type belongs to the filter: a collab keeps its
	 * own chats under the same PARENT_ID, and they carry PARENT_MID = 0, so any boundary covers them.
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupCommentChats(int $limit): bool
	{
		$chatIds = $this->collectByColumn(
			ChatTable::class,
			$this->chatId,
			min($limit, self::COMMENT_CHATS_PER_PASS),
			'PARENT_ID',
			additionalFilter: array_merge(
				['=TYPE' => Chat::IM_TYPE_COMMENT],
				$this->getMessageBoundaryFilter('PARENT_MID'),
			),
		);

		if (count($chatIds) === 0)
		{
			return false;
		}

		$batchBudget = self::COMMENT_CONTENT_BATCHES_PER_PASS;

		foreach ($chatIds as $commentChatId)
		{
			$batchBudget = (new self((int)$commentChatId))
				->withDeadline($this->deadline)
				->deleteChatWithinPass($limit, $batchBudget)
			;

			if ($batchBudget <= 0 || $this->isDeadlineReached())
			{
				break;
			}
		}

		return true;
	}

	/**
	 * Takes the chat down inside the caller's pass instead of scheduling a cleanup of its own: a
	 * channel behind whose border thousands of commented posts are left would otherwise put an agent
	 * in b_agent for every one of them, each walking the whole sequence on its own.
	 *
	 * The content goes first, the members after it and the chat row last, the reverse of
	 * {@see self::deleteChat()}: a pass that runs out of budget stops in the middle of a chat, and a row
	 * deleted ahead of its content would leave that content behind with nothing left to select it by.
	 * Until the row is gone the chat is still picked by the same walk, which restarts its sequence and
	 * resumes where the rows still are.
	 *
	 * @return int the batch budget left, zero meaning the pass has spent it all
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function deleteChatWithinPass(int $limit, int $batchBudget): int
	{
		$completedStep = null;

		while ($batchBudget > 0)
		{
			$progress = $this->processCleanup($limit, $completedStep);

			if ($progress->isFinished)
			{
				$batchBudget = $this->detachMembersWithinPass($limit, $batchBudget);

				if ($batchBudget <= 0)
				{
					// The members are not all off the chat yet, and the row that selects it is still
					// there, so the next pass finds the chat again and resumes the walk.
					return 0;
				}

				$this->cleanupChatBasics(broadcastDeletePush: false);

				// Taking the chat down is work of its own: an empty one costs the whole sequence and a
				// full cleanupChatBasics(), so a pass pays for it as it pays for a batch of rows.
				return $batchBudget - 1;
			}

			// The step stays uncompleted while it still has rows, so a whole batch has just gone.
			if ($progress->completedStep === $completedStep)
			{
				--$batchBudget;
			}

			$completedStep = $progress->completedStep;

			// The content goes before the chat row, so a teardown left in the middle is picked up by the
			// same walk on the next pass and resumes where the rows still are.
			if ($this->isDeadlineReached())
			{
				return 0;
			}
		}

		return 0;
	}

	/**
	 * The members of the chat, a batch per call, ahead of the rows that hold the chat together. This is
	 * the work {@see self::cleanupChatBasics()} would otherwise do for every member of the chat at once,
	 * inside a single step of the pass: the delete push, the unread rows with the counters behind them
	 * and the anchor cache. A comment chat keeps a member for everyone who has ever opened the
	 * discussion - a reader is joined to it by the first request that names the chat
	 * ({@see \Bitrix\Im\V2\Controller\Filter\AutoJoinToChat}) - so a post the whole company has read
	 * leaves a walk as long as the post was popular, while the teardown itself is one step of one pass.
	 *
	 * No cursor is carried anywhere: a batch deletes the very relations it was drawn from, so the next
	 * one begins where this one stopped, and a walk that dies inside a batch is resumed by the pass that
	 * finds the chat by the row nobody has deleted yet ({@see self::deleteChatWithinPass()}). Nothing
	 * refills the selection meanwhile - the post stands behind the border of the channel, so the access
	 * check of the comment chat refuses everyone and no reader can join it any more.
	 *
	 * @return int the batch budget left, zero meaning the walk is unfinished or the pass has spent it all
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function detachMembersWithinPass(int $limit, int $batchBudget): int
	{
		$deletePush = $this->buildDeletePush(Chat::getInstance($this->chatId), null);

		while ($batchBudget > 0)
		{
			$userIds = $this->collectMembers($limit);

			if ($userIds === [])
			{
				return $batchBudget;
			}

			$this->detachMembers($userIds, $deletePush);
			--$batchBudget;

			// A batch always runs to its end: the rows it has taken are what the next walk no longer sees.
			if ($this->isDeadlineReached())
			{
				return 0;
			}
		}

		return 0;
	}

	/**
	 * The batch is a range of IX_IM_REL_6 (CHAT_ID, USER_ID), ordering included.
	 *
	 * @return int[]
	 */
	private function collectMembers(int $limit): array
	{
		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $this->chatId)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'USER_ID'));
	}

	/**
	 * Telling the batch comes before taking it off the chat, for the same reason it does in
	 * {@see self::cleanupMembers()}: a push repeated on the next pass reaches a client that has already
	 * closed the chat, while one lost after the relations are gone has no list of recipients left to be
	 * sent to. The pushes of the batches of one hit carry the same payload and are merged by pull into a
	 * single event, so a member hears about the chat once however many batches the walk took.
	 *
	 * @param int[] $userIds
	 */
	private function detachMembers(array $userIds, array $deletePush): void
	{
		$this->sendDeletePush($userIds, $deletePush);

		// The unread rows of the batch and the counters behind them. The service drops the cache of the
		// members who really held rows here, not of everyone the batch has drawn.
		ServiceLocator::getInstance()
			->get(CountersUpdater::class)
			->delete()
			->byChat($this->chatId)
			->forUsers($userIds)
			->execute()
		;
		$this->deleteAnchorsOfMembers($userIds);
		$this->deleteByColumn(RelationTable::class, $userIds, 'USER_ID', ['=CHAT_ID' => $this->chatId]);
	}

	/**
	 * The mentions and reactions addressed to the batch, and the cache of the members who really hold
	 * one: the pair (USER_ID, CHAT_ID) is what IX_B_IM_ANCHOR_2 covers, so both the reading and the
	 * deletion keep to a range of the index. Dropping the cache of the whole batch instead would rebuild
	 * the anchors of everyone the discussion has never named.
	 *
	 * @param int[] $userIds
	 */
	private function deleteAnchorsOfMembers(array $userIds): void
	{
		$rows = AnchorTable::query()
			->setSelect(['USER_ID'])
			->setDistinct()
			->where('CHAT_ID', $this->chatId)
			->whereIn('USER_ID', $userIds)
			->fetchAll()
		;
		$ownerIds = array_map('intval', array_column($rows, 'USER_ID'));

		if ($ownerIds === [])
		{
			return;
		}

		$this->deleteByColumn(AnchorTable::class, $ownerIds, 'USER_ID', ['=CHAT_ID' => $this->chatId]);
		AnchorContainer::getInstance()->getAnchorProvider()->cleanUsersCache($ownerIds);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupMessages(int $limit): bool
	{
		$ids = $this->collectByColumn(
			MessageTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter('ID'),
		);

		if (count($ids) === 0)
		{
			if (!$this->isHistoryCleanup())
			{
				$folderId = Chat::getInstance($this->chatId)->getDiskFolderId();

				if ($folderId)
				{
					$folderModel = Folder::getById($folderId);
					$folderModel?->deleteTree(SystemUser::SYSTEM_USER_ID);
				}
			}

			return false;
		}

		$params = $this->collectByColumn(
			MessageParamTable::class,
			$ids,
			columnName: 'MESSAGE_ID',
			selectColumnName: [
				'ID',
				'PARAM_NAME',
				'PARAM_VALUE',
			],
		);
		$paramIds = array_keys($params);
		$fileIds = [];

		foreach ($params as $row)
		{
			if ($row['PARAM_NAME'] === 'FILE_ID')
			{
				$fileIds[] = (int)$row['PARAM_VALUE'];
			}
		}

		$fileIds = array_values(array_unique($fileIds));

		// Ahead of the rows, because the walk may run out of time inside the batch: a file left over keeps
		// the message pointing at it, and the same selection brings both back on the next pass. The rows of
		// this very batch are no answer to the ownership check either way - they name this chat and stand
		// behind the border.
		if (count($this->deleteChatFiles($fileIds)) < count($fileIds))
		{
			return true;
		}

		$this->deleteByColumn(MessageTable::class, $ids);
		$this->deleteByColumn(MessageUuidTable::class, $ids, 'MESSAGE_ID');
		$this->deleteByColumn(MessageIndexTable::class, $ids, 'MESSAGE_ID');
		$this->deleteByColumn(MessageViewedTable::class, $ids, 'MESSAGE_ID');
		$this->deleteByColumn(MessageDisappearingTable::class, $ids, 'MESSAGE_ID');
		$this->deleteByColumn(MessageParamTable::class, $paramIds);
		$this->deleteByColumn(LinkPinTable::class, $ids, 'MESSAGE_ID');
		$this->deleteByColumn(LinkPinPersonalTable::class, $ids, 'MESSAGE_ID');

		return true;
	}

	/**
	 * Pointers to mentions and reactions behind the border: they carry no text, but they keep counters
	 * alive for messages nobody can open any more. A step of its own, and one that runs ahead of the
	 * messages it points at: a single message mentioning everybody holds an anchor per member
	 * ({@see \Bitrix\Im\V2\Anchor\AnchorService::addMentionAnchor()}), so the batch of the messages
	 * says nothing about how many anchors - and how many owners - go with them.
	 *
	 * The anchors are served from a cache per user and every owner of a deleted one costs an
	 * invalidation of their own, so the batch is counted in anchors rather than in messages: the owners
	 * of a batch are never more than the rows in it, and the pass measures its time between batches.
	 *
	 * The rows are taken by CHAT_ID (IX_B_IM_ANCHOR_3), which InnoDB extends with the primary key, so
	 * the batch is a range of the index and the border is checked on the rows it returns. No cursor is
	 * kept: a batch is deleted before the next one is asked for.
	 *
	 * The full deletion has no use for any of it - it drops the anchors of the chat by CHAT_ID in
	 * cleanupChatBasics() - and this step is not in its sequence.
	 */
	protected function cleanupAnchors(int $limit): bool
	{
		$rows = $this->collectByColumn(
			AnchorTable::class,
			$this->chatId,
			$limit,
			selectColumnName: ['ID', 'USER_ID'],
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($rows) === 0)
		{
			return false;
		}

		$this->deleteByColumn(AnchorTable::class, array_keys($rows));
		$this->cleanupAnchorCache(array_unique(array_column($rows, 'USER_ID')));

		return true;
	}

	/**
	 * The files behind the border, ahead of their messages. A message behind the border is unreachable
	 * the moment the border is set, while its file stays open to every member through the rights of the
	 * chat folder, and to anyone holding a signed link, until the file itself is gone. Deleted along
	 * with their messages, the files of a chat would spread over every pass of the cleanup; this step
	 * takes them down in the first ones. The rows go with the files: the sidebar has nothing to list for
	 * a message nobody can open. The rows of a file the chat still shows are not here at all
	 * ({@see self::collectClearedFileRows()}). A FILE_ID param of a message without a row is still
	 * handled by cleanupMessages(), which finds the files of this step already gone.
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupFiles(int $limit): bool
	{
		$rows = $this->collectClearedFileRows(min($limit, self::FILES_PER_PASS));

		if (count($rows) === 0)
		{
			return false;
		}

		$handledFileIds = array_flip($this->deleteChatFiles(
			array_unique(array_map('intval', array_column($rows, 'DISK_FILE_ID')))
		));

		// Only the rows of the files the pass has actually reached. A row deleted ahead of its file would
		// leave that file with nothing left to select it by, and the walk always reaches at least one, so
		// the step keeps moving.
		$rowIds = [];
		foreach ($rows as $rowId => $row)
		{
			if (isset($handledFileIds[(int)$row['DISK_FILE_ID']]))
			{
				$rowIds[] = $rowId;
			}
		}

		$this->deleteByColumn(LinkFileTable::class, $rowIds);

		return true;
	}

	/**
	 * FILE_ID is a message param, and the upload path only logs a file that does not belong to the
	 * chat folder instead of refusing it. Deletion runs with system rights, so the ownership has to
	 * be confirmed here: a file living outside this chat is somebody else's and stays.
	 *
	 * A Disk deletion takes down versions, rights, attachments and previews of its own, so a batch of
	 * them outlives the budget of the pass long before the batch is over. The walk gives the pass back
	 * between two files and answers how far it got; the caller keeps whatever selects the rest.
	 *
	 * @param int[] $fileIds
	 * @return int[] the ids this call is done with - deleted, kept as somebody else's, or unknown to Disk
	 */
	private function deleteChatFiles(array $fileIds): array
	{
		if (empty($fileIds))
		{
			return [];
		}

		$folderId = (int)Chat::getInstance($this->chatId)->getDiskFolderId();

		if ($folderId <= 0)
		{
			return $fileIds;
		}

		$filesToDelete = $this->filterFilesStillLinked($fileIds);

		if ($filesToDelete === [])
		{
			return $fileIds;
		}

		$handled = array_values(array_diff($fileIds, $filesToDelete));
		$models = $this->loadDiskFiles($filesToDelete);

		foreach ($filesToDelete as $fileId)
		{
			$handled[] = $fileId;
			$file = $models[$fileId] ?? null;

			if ($file !== null && (int)$file->getParentId() === $folderId)
			{
				$file->delete(SystemUser::SYSTEM_USER_ID);
			}

			// Asked after a deletion and never before one: a pass out of time still takes a file with it,
			// so the walk cannot stall on a deadline that is already behind it.
			if ($this->isDeadlineReached())
			{
				break;
			}
		}

		return $handled;
	}

	/**
	 * The models of a batch in one query. Not File::loadBatchById(): it is inherited without the narrowing
	 * File::load() does, so a folder id - and FILE_ID is a message param, written by paths that only log
	 * what they do not recognise - reaches the model factory and makes it throw, taking the whole step
	 * with it.
	 *
	 * @param int[] $fileIds
	 * @return array<int, File>
	 */
	private function loadDiskFiles(array $fileIds): array
	{
		$models = File::getModelList([
			'select' => ['*'],
			'filter' => ['@ID' => $fileIds, '=TYPE' => ObjectTable::TYPE_FILE],
		]);
		$byId = [];

		foreach ($models as $model)
		{
			$byId[(int)$model->getId()] = $model;
		}

		return $byId;
	}

	/**
	 * A disk file may hang off more than one message: the commit path attaches an already uploaded
	 * file by id, without copying it. The file stays as long as a message above the border, one of
	 * another chat, or an upload still waiting for its message (MESSAGE_ID = 0), still shows it. The
	 * link rows behind the border go with it when another chat is what keeps it, and stay when this
	 * chat is ({@see self::collectClearedFileRows()}).
	 * The full deletion skips the check: every message of the chat is going, and so is the folder.
	 *
	 * Kept alive here is not the same as reachable: the signed url of a file whose owner has cleared
	 * the message showing it is refused meanwhile
	 * ({@see \Bitrix\Im\V2\Link\File\FileService::isBehindChatHistoryBorder()}), and the owner is the
	 * chat this walk would delete the file for - the chat holding it in its folder, never one that
	 * merely attached it.
	 *
	 * The answer is a set of file ids, never the rows carrying them: one file re-attached over and over
	 * has as many rows as it has messages, and the check asks nothing of them beyond their existence.
	 * Both selections group by the id, so the batch costs the database a bounded read either way.
	 *
	 * @param int[] $fileIds
	 * @return int[]
	 */
	private function filterFilesStillLinked(array $fileIds): array
	{
		if (!$this->isHistoryCleanup())
		{
			return $fileIds;
		}

		$rows = LinkFileTable::query()
			->setSelect(['DISK_FILE_ID'])
			->whereIn('DISK_FILE_ID', $fileIds)
			->where(
				Query::filter()
					->logic('or')
					->whereNot('CHAT_ID', $this->chatId)
					->where('MESSAGE_ID', '>', $this->messageIdBoundary)
					->where('MESSAGE_ID', 0)
			)
			->setGroup(['DISK_FILE_ID'])
			->fetchAll()
		;
		$stillLinked = array_flip(array_map('intval', array_column($rows, 'DISK_FILE_ID')));

		foreach ($this->collectFilesLinkedByMessageParam($fileIds) as $fileId)
		{
			$stillLinked[$fileId] = true;
		}

		return array_values(array_filter(
			$fileIds,
			static fn (int $fileId): bool => !isset($stillLinked[$fileId])
		));
	}

	/**
	 * A message the migration has not reached shows its file through the FILE_ID param alone, so a link
	 * row is not the whole answer yet: a file whose only surviving message is one of those would be
	 * taken from it. The deletion runs with system rights and cannot be taken back, so the link is
	 * looked for wherever it is still left.
	 *
	 * @param int[] $fileIds
	 * @return int[]
	 */
	private function collectFilesLinkedByMessageParam(array $fileIds): array
	{
		if (FileService::isLinkMigrationFinished())
		{
			return [];
		}

		$rows = MessageParamTable::query()
			->setSelect(['PARAM_VALUE'])
			->where('PARAM_NAME', \Bitrix\Im\V2\Message\Params::FILE_ID)
			->whereIn('PARAM_VALUE', array_map('strval', $fileIds))
			->where(
				Query::filter()
					->logic('or')
					->whereNot('MESSAGE.CHAT_ID', $this->chatId)
					->where('MESSAGE_ID', '>', $this->messageIdBoundary)
			)
			->setGroup(['PARAM_VALUE'])
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'PARAM_VALUE'));
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupFavoritesLinks(int $limit): bool
	{
		$ids = $this->collectByColumn(
			LinkFavoriteTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LinkFavoriteTable::class, $ids);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupUrlsLinks(int $limit): bool
	{
		$ids = $this->collectByColumn(
			LinkUrlTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LinkUrlIndexTable::class, $ids, 'URL_ID');
		$this->deleteByColumn(LinkUrlTable::class, $ids);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupTasksLinks(int $limit): bool
	{
		$ids = $this->collectByColumn(
			LinkTaskTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LinkTaskTable::class, $ids);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupCalendarLinks(int $limit): bool
	{
		$ids = $this->collectByColumn(
			LinkCalendarTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LinkCalendarIndexTable::class, $ids);
		$this->deleteByColumn(LinkCalendarTable::class, $ids);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupFilesLinks(int $limit): bool
	{
		$rowIds = array_keys($this->collectClearedFileRows($limit));

		if (count($rowIds) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LinkFileTable::class, $rowIds);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupReactions(int $limit): bool
	{
		$ids = $this->collectByColumn(
			ReactionTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(ReactionTable::class, $ids);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupReminder(int $limit): bool
	{
		$ids = $this->collectByColumn(
			LinkReminderTable::class,
			$this->chatId,
			$limit,
			additionalFilter: $this->getMessageBoundaryFilter(),
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(LinkReminderTable::class, $ids);

		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	protected function cleanupNoRelationPermDisk(int $limit): bool
	{
		$ids = $this->collectByColumn(
			NoRelationPermissionDiskTable::class,
			$this->chatId,
			$limit,
		);

		if (count($ids) === 0)
		{
			return false;
		}

		$this->deleteByColumn(NoRelationPermissionDiskTable::class, $ids);

		return true;
	}

	protected function cleanupChatFields(int $limit): bool
	{
		$this->updateChatFields();

		return false;
	}

	/**
	 * The fields of the chat itself, which the hit writes as soon as the history is hidden
	 * ({@see ChatCleanupService::finishCleanupForAll()}) and the scheduled cleanup writes again as its
	 * last step: a hit that dies inside the walk over the members runs neither of them, and nothing else
	 * ever brings LAST_MESSAGE_ID, PREV_MESSAGE_ID and MESSAGE_COUNT off the messages the cleanup takes
	 * away. Both writes are the same conditional statement against the border, so the later one is either
	 * a repeat that changes nothing or the only one there was.
	 *
	 * MESSAGE_COUNT is counted from the border rather than reduced by the count the request read at its
	 * start: two cleanups running over each other would take the same messages away twice, and nothing
	 * ever recounts the field afterwards - the removal of the content leaves it alone. The count is only
	 * asked for when the chat points past the border, that is when a message arrived while the cleanup was
	 * running: an emptied history is the ordinary outcome, and a chat the size of the portal is not worth
	 * a scan to arrive at a zero. It is assigned ahead of LAST_MESSAGE_ID because MySQL evaluates the
	 * assignments of one UPDATE left to right, and further down the list that column would already carry
	 * the value this same statement has reset.
	 */
	public function updateChatFields(): void
	{
		$lastMessageId = $this->messageIdBoundary;

		if ($lastMessageId === null)
		{
			return;
		}

		ChatTable::update($this->chatId, [
			'MESSAGE_COUNT' => new SqlExpression(
				'CASE WHEN ?# <= ?i THEN 0 ELSE (SELECT COUNT(1) FROM ?# WHERE ?# = ?i AND ?# > ?i) END',
				'LAST_MESSAGE_ID', $lastMessageId,
				MessageTable::getTableName(), 'CHAT_ID', $this->chatId, 'ID', $lastMessageId
			),
			'LAST_MESSAGE_ID' => new SqlExpression(
				'CASE WHEN ?# <= ?i THEN 0 ELSE ?# END',
				'LAST_MESSAGE_ID', $lastMessageId, 'LAST_MESSAGE_ID'
			),
			'PREV_MESSAGE_ID' => new SqlExpression(
				'CASE WHEN ?# <= ?i THEN 0 ELSE ?# END',
				'PREV_MESSAGE_ID', $lastMessageId, 'PREV_MESSAGE_ID'
			),
		]);

		Chat::cleanCache($this->chatId);
	}
}