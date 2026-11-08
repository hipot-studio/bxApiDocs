<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Mail\Helper\Mailbox\Options\EntityOptionsHelper;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Helper\MessageConversationStepper;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\MessageConversationMemberTable;
use Bitrix\Mail\Internals\MessageConversationActivityTable;
use Bitrix\Mail\Internals\MessageConversationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;

/**
 * Read side of the conversation grid: one page of conversations and the batched summaries of that
 * page.
 *
 * Every method here is bounded by the page: a summary is never read per conversation, so the number
 * of statements does not grow with the number of groups on the screen.
 */
class MessageConversationListRepository
{
	/** Mailbox property the backfill sets once its pass and reconciliation are complete. */
	public const PROJECTION_READY_PROPERTY = 'CONVERSATION_PROJECTION_READY';

	private const PROJECTION_VERSION = '2';
	private const SEEN_STATUSES = ['Y', 'S'];
	private static ?bool $activityProjectionInstalled = null;

	/** Whether the projection of the mailbox is complete enough to group the list by conversations. */
	public function isProjectionReady(int $mailboxId): bool
	{
		if (!$this->isActivityProjectionInstalled())
		{
			return false;
		}

		$row = MailEntityOptionsTable::getList([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
				'=ENTITY_ID' => (string)$mailboxId,
				'=PROPERTY_NAME' => self::PROJECTION_READY_PROPERTY,
			],
			'limit' => 1,
		])->fetch();

		if ($row === false || (string)$row['VALUE'] === 'N')
		{
			return false;
		}
		$state = (string)$row['VALUE'];
		if (str_starts_with($state, 'M:'))
		{
			return false;
		}
		if ($state !== $this->activityScopeToken($mailboxId))
		{
			$this->setProjectionReady($mailboxId, false);
			MessageConversationStepper::bind();

			return false;
		}

		return true;
	}

	private function isActivityProjectionInstalled(): bool
	{
		if (self::$activityProjectionInstalled !== null)
		{
			return self::$activityProjectionInstalled;
		}

		try
		{
			MessageConversationActivityTable::query()
				->setSelect(['MAILBOX_ID'])
				->setLimit(1)
				->fetch()
			;
			self::$activityProjectionInstalled = true;
		}
		catch (\Throwable)
		{
			self::$activityProjectionInstalled = false;
		}

		return self::$activityProjectionInstalled;
	}

	/**
	 * Write side of the same property, so that the only spelling of it stays in this class.
	 *
	 * Readiness is not a switch of the feature but a statement about the data: only a completed pass
	 * of the backfill sets it, and anything that can leave the projection behind takes it back.
	 */
	public function setProjectionReady(int $mailboxId, bool $ready): void
	{
		EntityOptionsHelper::setValue(
			$mailboxId,
			EntityOptionsType::Mailbox,
			(string)$mailboxId,
			self::PROJECTION_READY_PROPERTY,
			$ready ? $this->activityScopeToken($mailboxId) : 'N',
		);
	}

	/**
	 * Claims the readiness mark for one placement mutation.
	 *
	 * A concurrent claimant invalidates the first claim, so neither request can expose a projection
	 * refreshed for only its own messages.
	 */
	public function beginProjectionMutation(int $mailboxId): ?string
	{
		$claim = 'M:' . substr(hash('sha256', UuidGenerator::generateV4()), 0, 30);
		if ($this->compareAndSetProjectionState($mailboxId, $this->activityScopeToken($mailboxId), $claim))
		{
			return $claim;
		}

		$this->setProjectionReady($mailboxId, false);

		return null;
	}

	public function finishProjectionMutation(int $mailboxId, string $claim): bool
	{
		return $this->compareAndSetProjectionState(
			$mailboxId,
			$claim,
			$this->activityScopeToken($mailboxId),
		);
	}

	public function cancelProjectionMutation(int $mailboxId, ?string $claim): void
	{
		if ($claim === null || !$this->compareAndSetProjectionState($mailboxId, $claim, 'N'))
		{
			$this->setProjectionReady($mailboxId, false);
		}
	}

	private function compareAndSetProjectionState(int $mailboxId, string $from, string $to): bool
	{
		$entity = MailEntityOptionsTable::getEntity();
		$connection = $entity->getConnection();
		[$update] = $connection->getSqlHelper()->prepareUpdate(
			$entity->getDbTableName(),
			['VALUE' => $to],
		);
		$filter = Query::buildFilterSql($entity, [
			'=MAILBOX_ID' => $mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'=ENTITY_ID' => (string)$mailboxId,
			'=PROPERTY_NAME' => self::PROJECTION_READY_PROPERTY,
			'=VALUE' => $from,
		]);

		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$update,
			$filter,
		));

		return $connection->getAffectedRowsCount() === 1;
	}

	private function activityScopeToken(int $mailboxId): string
	{
		$folderHashes = [];
		foreach ($this->findSyncFolders($mailboxId) as $folder)
		{
			if (!$folder['isSpam'] && !$folder['isTrash'])
			{
				$folderHashes[] = $folder['md5'];
			}
		}
		sort($folderHashes, SORT_STRING);

		return 'Y:' . substr(hash('sha256', implode('|', [
			self::PROJECTION_VERSION,
			GenerationScope::forMailbox($mailboxId)->getStampGenerationId(),
			...$folderHashes,
		])), 0, 29);
	}

	/**
	 * Folders of the mailbox that take part in the grid, by their path.
	 *
	 * @return array<string, array{md5: string, name: string, isSpam: bool, isTrash: bool, isDraft: bool,
	 *     isOutcome: bool}>
	 */
	public function findSyncFolders(int $mailboxId): array
	{
		$folders = [];
		$rows = MailboxDirectoryTable::getList([
			'select' => ['PATH', 'NAME', 'DIR_MD5', 'IS_SPAM', 'IS_TRASH', 'IS_DRAFT', 'IS_OUTCOME'],
			'filter' => GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=IS_SYNC' => MailboxDirectoryTable::ACTIVE,
			]),
		])->fetchAll();
		foreach ($rows as $row)
		{
			$folders[(string)$row['PATH']] = [
				'md5' => (string)$row['DIR_MD5'],
				'name' => (string)$row['NAME'],
				'isSpam' => (int)$row['IS_SPAM'] === MailboxDirectoryTable::ACTIVE,
				'isTrash' => (int)$row['IS_TRASH'] === MailboxDirectoryTable::ACTIVE,
				'isDraft' => (int)$row['IS_DRAFT'] === MailboxDirectoryTable::ACTIVE,
				'isOutcome' => (int)$row['IS_OUTCOME'] === MailboxDirectoryTable::ACTIVE,
			];
		}

		return $folders;
	}

	/**
	 * One bounded portion of the visible uid rows of the conversation in one folder, ordered by the
	 * identifier of the row so that the next portion continues where this one stopped.
	 *
	 * A group action reads its targets through this method right before it changes them: what a portion
	 * returns is what is visible at that very moment, and never a set the caller brought with it.
	 *
	 * @return list<array{uidId: string, messageId: int, isSeen: bool}>
	 */
	public function findConversationUidRows(
		int $mailboxId,
		int $conversationId,
		string $dirMd5,
		int $limit,
		string $afterUidId = '',
	): array
	{
		$query = $this->buildScopeQuery($mailboxId, [$dirMd5])
			->where('CONVERSATION_ID', $conversationId)
			->addOrder('UID.ID', 'ASC')
			->setLimit($limit)
		;
		if ($afterUidId !== '')
		{
			$query->where('UID.ID', '>', $afterUidId);
		}

		return $this->fetchUidRows($query);
	}

	/**
	 * Visible uid rows of the newest message of the conversation in the folder, all of them: a message
	 * the folder holds twice is still one message and is changed as one.
	 *
	 * @return list<array{uidId: string, messageId: int, isSeen: bool}>
	 */
	public function findNewestConversationUidRows(int $mailboxId, int $conversationId, string $dirMd5): array
	{
		$newest = $this->buildScopeQuery($mailboxId, [$dirMd5])
			->addSelect('MESSAGE_ID')
			->where('CONVERSATION_ID', $conversationId)
			->addOrder('UID.INTERNALDATE', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->setLimit(1)
			->fetch()
		;
		if ($newest === false)
		{
			return [];
		}

		return $this->fetchUidRows(
			$this->buildScopeQuery($mailboxId, [$dirMd5])
				->where('CONVERSATION_ID', $conversationId)
				->where('MESSAGE_ID', (int)$newest['MESSAGE_ID'])
				->addOrder('UID.ID', 'ASC'),
		);
	}

	/** Mailbox of the conversation, or null when the projection no longer knows the identifier. */
	public function findConversationMailboxId(int $conversationId): ?int
	{
		$row = MessageConversationTable::query()
			->setSelect(['MAILBOX_ID'])
			->where('ID', $conversationId)
			->fetch()
		;

		return $row === false ? null : (int)$row['MAILBOX_ID'];
	}

	/**
	 * One page of conversations ordered by the newest available message of the whole conversation.
	 *
	 * @param string[] $dirMd5List folders the summary is computed over
	 * @param array|null $matchedFilter ORM filter of the current search; null keeps every conversation
	 * @param array{date: DateTime, id: int}|null $cursor position of the previous page
	 * @return list<array{conversationId: int, lastActivity: DateTime}>
	 */
	public function findConversationPage(
		int $mailboxId,
		array $dirMd5List,
		?array $matchedFilter,
		?array $cursor,
		int $limit,
	): array
	{
		$query = MessageConversationActivityTable::query()
			->setSelect(['CONVERSATION_ID', 'LAST_ACTIVITY'])
			->where('MAILBOX_ID', $mailboxId)
			->where('SCOPE_KEY', $this->activityScopeKey($dirMd5List))
			->addOrder('LAST_ACTIVITY', 'DESC')
			->addOrder('CONVERSATION_ID', 'DESC')
			->setLimit($limit)
		;
		if ($matchedFilter !== null)
		{
			$query->whereIn('CONVERSATION_ID', $this->matchedConversationIdsQuery($mailboxId, $matchedFilter));
		}
		if ($cursor !== null)
		{
			$this->applyKeysetBound($query, 'LAST_ACTIVITY', 'CONVERSATION_ID', $cursor);
		}

		return array_map(
			static fn(array $row): array => [
				'conversationId' => (int)$row['CONVERSATION_ID'],
				'lastActivity' => $row['LAST_ACTIVITY'],
			],
			$query->fetchAll(),
		);
	}

	/** @param string[] $dirMd5List */
	private function activityScopeKey(array $dirMd5List): string
	{
		return count($dirMd5List) === 1
			? MessageConversationActivityTable::folderScope((string)reset($dirMd5List))
			: MessageConversationActivityTable::ALL_SYNC_SCOPE;
	}

	/**
	 * One page of conversations of the drafts view: conversations of the mailbox the user has an
	 * active reply draft in, ordered by the newest of those drafts.
	 *
	 * @param string[] $dirMd5List
	 * @param array{date: DateTime, id: int}|null $cursor
	 * @return list<array{conversationId: int, lastActivity: DateTime}>
	 */
	public function findDraftConversationPage(
		int $mailboxId,
		int $userId,
		array $dirMd5List,
		?array $cursor,
		int $limit,
	): array
	{
		$query = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField($this->activeDraftReference())
			->addSelect('CONVERSATION_ID')
			->registerRuntimeField('LAST_ACTIVITY', $this->newestDateField('REPLY_DRAFT.DATE_MODIFY'))
			->addSelect('LAST_ACTIVITY')
			->where('REPLY_DRAFT.USER_ID', $userId)
			->addGroup('CONVERSATION_ID')
			->addOrder('LAST_ACTIVITY', 'DESC')
			->addOrder('CONVERSATION_ID', 'DESC')
			->setLimit($limit)
		;
		if ($cursor !== null)
		{
			$this->applyKeysetBound($query, 'LAST_ACTIVITY', 'CONVERSATION_ID', $cursor);
		}

		return array_map(
			static fn(array $row): array => [
				'conversationId' => (int)$row['CONVERSATION_ID'],
				'lastActivity' => $row['LAST_ACTIVITY'],
			],
			$query->fetchAll(),
		);
	}

	/**
	 * Available messages and unread messages of every conversation of the page.
	 *
	 * @param int[] $conversationIds
	 * @param string[] $dirMd5List
	 * @return array<int, array{messageCount: int, unreadCount: int}>
	 */
	public function countConversationMessages(int $mailboxId, array $conversationIds, array $dirMd5List): array
	{
		if ($conversationIds === [])
		{
			return [];
		}

		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('CONVERSATION_ID')
			->registerRuntimeField('MESSAGE_COUNT', $this->messageCountField('MESSAGE_COUNT'))
			->registerRuntimeField('UNREAD_COUNT', $this->unreadCountField())
			->addSelect('MESSAGE_COUNT')
			->addSelect('UNREAD_COUNT')
			->whereIn('CONVERSATION_ID', $conversationIds)
			->addGroup('CONVERSATION_ID')
			->fetchAll()
		;

		$counts = [];
		foreach ($rows as $row)
		{
			$counts[(int)$row['CONVERSATION_ID']] = [
				'messageCount' => (int)$row['MESSAGE_COUNT'],
				'unreadCount' => (int)$row['UNREAD_COUNT'],
			];
		}

		return $counts;
	}

	/**
	 * Revisions of the conversations of one page, maintained atomically by the projection write side.
	 *
	 * @param int[] $conversationIds
	 * @return array<int, int>
	 */
	public function findConversationRevisions(int $mailboxId, array $conversationIds): array
	{
		if ($conversationIds === [])
		{
			return [];
		}

		$rows = MessageConversationTable::query()
			->setSelect(['ID', 'REVISION'])
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('ID', $conversationIds)
			->fetchAll()
		;
		$revisions = [];
		foreach ($rows as $row)
		{
			$revisions[(int)$row['ID']] = (int)$row['REVISION'];
		}

		return $revisions;
	}

	/**
	 * Canonical fingerprints of active reply draft identifiers in every conversation.
	 *
	 * The join starts from messages visible in the current folder scope. Grouping removes duplicate
	 * folder placements, while the user filter keeps drafts of shared mailbox users isolated.
	 *
	 * @param int[] $conversationIds
	 * @param string[] $dirMd5List
	 * @return array<int, string>
	 */
	public function findConversationDraftMembershipFingerprints(
		int $mailboxId,
		int $userId,
		array $conversationIds,
		array $dirMd5List,
	): array
	{
		if ($conversationIds === [])
		{
			return [];
		}

		$result = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField($this->activeDraftReference())
			->addSelect('CONVERSATION_ID')
			->addSelect('REPLY_DRAFT.ID', 'DRAFT_ID')
			->where('REPLY_DRAFT.USER_ID', $userId)
			->whereIn('CONVERSATION_ID', $conversationIds)
			->addGroup('CONVERSATION_ID')
			->addGroup('REPLY_DRAFT.ID')
			->addOrder('CONVERSATION_ID', 'ASC')
			->addOrder('REPLY_DRAFT.ID', 'ASC')
			->exec()
		;

		$fingerprints = [];
		$currentConversationId = 0;
		$context = null;
		while ($row = $result->fetch())
		{
			$conversationId = (int)$row['CONVERSATION_ID'];
			if ($conversationId !== $currentConversationId)
			{
				if ($context !== null)
				{
					$fingerprints[$currentConversationId] = hash_final($context);
				}

				$currentConversationId = $conversationId;
				$context = hash_init('sha256');
			}

			hash_update($context, (int)$row['DRAFT_ID'] . "\n");
		}
		if ($context !== null)
		{
			$fingerprints[$currentConversationId] = hash_final($context);
		}

		return $fingerprints;
	}

	/**
	 * Messages of every conversation of the page that matched the current search or filter.
	 *
	 * @param int[] $conversationIds
	 * @return array<int, int>
	 */
	public function countMatchedMessages(int $mailboxId, array $conversationIds, array $matchedFilter): array
	{
		if ($conversationIds === [])
		{
			return [];
		}

		$rows = MessageConversationMemberTable::query()
			->addSelect('CONVERSATION_ID')
			->registerRuntimeField(
				'MATCHED_COUNT',
				new ExpressionField('MATCHED_COUNT', 'COUNT(DISTINCT %s)', ['MESSAGE_ID']),
			)
			->addSelect('MATCHED_COUNT')
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('CONVERSATION_ID', $conversationIds)
			->whereIn('MESSAGE_ID', $this->matchedMessageIdsQuery($matchedFilter))
			->addGroup('CONVERSATION_ID')
			->fetchAll()
		;

		$counts = [];
		foreach ($rows as $row)
		{
			$counts[(int)$row['CONVERSATION_ID']] = (int)$row['MATCHED_COUNT'];
		}

		return $counts;
	}

	/**
	 * Message that represents every conversation of the page: the newest available one, and the
	 * largest identifier among those sharing that date.
	 *
	 * @param list<array{conversationId: int, lastActivity: DateTime}> $conversations
	 * @param string[] $dirMd5List
	 * @return array<int, int>
	 */
	public function findRepresentativeMessageIds(
		int $mailboxId,
		array $conversations,
		array $dirMd5List,
	): array
	{
		if ($conversations === [])
		{
			return [];
		}

		$latestMessages = (new ConditionTree())->logic(ConditionTree::LOGIC_OR);
		foreach ($conversations as $conversation)
		{
			$latestMessages->where(
				(new ConditionTree())
					->where('CONVERSATION_ID', $conversation['conversationId'])
					->where('UID.INTERNALDATE', $conversation['lastActivity']),
			);
		}

		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('CONVERSATION_ID')
			->registerRuntimeField('REPRESENTATIVE_ID', $this->representativeIdField())
			->addSelect('REPRESENTATIVE_ID')
			->where($latestMessages)
			->addGroup('CONVERSATION_ID')
			->fetchAll()
		;

		$messageIds = [];
		foreach ($rows as $row)
		{
			$messageIds[(int)$row['CONVERSATION_ID']] = (int)$row['REPRESENTATIVE_ID'];
		}

		return $messageIds;
	}

	/**
	 * Newest available message date of every conversation in the current folder scope.
	 *
	 * Draft rows are ordered by their draft date, which is not a message date and therefore cannot be
	 * used to select their representative message.
	 *
	 * @param int[] $conversationIds
	 * @param string[] $dirMd5List
	 * @return array<int, DateTime>
	 */
	public function findLatestMessageActivities(
		int $mailboxId,
		array $conversationIds,
		array $dirMd5List,
	): array
	{
		if ($conversationIds === [])
		{
			return [];
		}

		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('CONVERSATION_ID')
			->registerRuntimeField('LAST_ACTIVITY', $this->newestDateField('UID.INTERNALDATE'))
			->addSelect('LAST_ACTIVITY')
			->whereIn('CONVERSATION_ID', $conversationIds)
			->addGroup('CONVERSATION_ID')
			->fetchAll()
		;

		$activities = [];
		foreach ($rows as $row)
		{
			$activities[(int)$row['CONVERSATION_ID']] = $row['LAST_ACTIVITY'];
		}

		return $activities;
	}

	/**
	 * Active reply drafts of the user in every conversation of the page.
	 *
	 * @param int[] $conversationIds
	 * @param string[] $dirMd5List
	 * @return array<int, int>
	 */
	public function countReplyDrafts(
		int $mailboxId,
		int $userId,
		array $conversationIds,
		array $dirMd5List,
	): array
	{
		if ($conversationIds === [])
		{
			return [];
		}

		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField($this->activeDraftReference())
			->addSelect('CONVERSATION_ID')
			->registerRuntimeField('DRAFT_COUNT', $this->replyDraftCountField())
			->addSelect('DRAFT_COUNT')
			->where('REPLY_DRAFT.USER_ID', $userId)
			->whereIn('CONVERSATION_ID', $conversationIds)
			->addGroup('CONVERSATION_ID')
			->fetchAll()
		;

		$counts = [];
		foreach ($rows as $row)
		{
			$counts[(int)$row['CONVERSATION_ID']] = (int)$row['DRAFT_COUNT'];
		}

		return $counts;
	}

	/**
	 * Conversation of every given message that is available in the current scope. A message the
	 * scope does not show has no conversation here, so a reply draft of it stays a row of its own.
	 *
	 * @param int[] $messageIds
	 * @param string[] $dirMd5List
	 * @return array<int, int>
	 */
	public function findAvailableConversationsOfMessages(
		int $mailboxId,
		array $messageIds,
		array $dirMd5List,
	): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('MESSAGE_ID')
			->addSelect('CONVERSATION_ID')
			->whereIn('MESSAGE_ID', $messageIds)
			->addGroup('MESSAGE_ID')
			->addGroup('CONVERSATION_ID')
			->fetchAll()
		;

		$conversations = [];
		foreach ($rows as $row)
		{
			$conversations[(int)$row['MESSAGE_ID']] = (int)$row['CONVERSATION_ID'];
		}

		return $conversations;
	}

	/**
	 * Active mail drafts that are not represented by a conversation in the current scope.
	 *
	 * @param string[] $dirMd5List
	 * @param array{date: DateTime, id: int}|null $cursor
	 * @return int[]
	 */
	public function findStandaloneDraftPage(
		int $mailboxId,
		int $userId,
		array $dirMd5List,
		?array $cursor,
		int $limit,
	): array
	{
		$unavailableParent = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->whereNull('PARENT_MESSAGE_ID')
			->whereNotIn('PARENT_MESSAGE_ID', $this->availableMessageIdsQuery($mailboxId, $dirMd5List))
		;
		$replyDrafts = (new ConditionTree())
			->where('COMPOSE_MODE', DraftTable::COMPOSE_MODE_REPLY)
			->where($unavailableParent)
		;
		$standaloneDrafts = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->whereNot('COMPOSE_MODE', DraftTable::COMPOSE_MODE_REPLY)
			->where($replyDrafts)
		;
		$query = DraftTable::query()
			->addSelect('ID')
			->where('USER_ID', $userId)
			->where('CONTEXT_TYPE', DraftTable::CONTEXT_MAIL)
			->where('STATUS', DraftTable::STATUS_ACTIVE)
			->where('DATE_EXPIRE', '>', new DateTime())
			->where($standaloneDrafts)
			->addOrder('DATE_MODIFY', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit(max(1, $limit))
		;
		if ($cursor !== null)
		{
			$this->applyKeysetBound($query, 'DATE_MODIFY', 'ID', $cursor);
		}

		return array_map('intval', array_column($query->fetchAll(), 'ID'));
	}

	/**
	 * Facts the version of a conversation row is built from, read over the folders that very row is
	 * summarized over. The expansion of a row repeats its version, so both read them here.
	 *
	 * @param string[] $dirMd5List
	 * @return array{messageCount: int, representativeMessageId: int, draftCount: int, revision: int,
	 *     membershipFingerprint: int, draftMembershipFingerprint: string}
	 */
	public function findConversationVersionFacts(
		int $mailboxId,
		int $userId,
		int $conversationId,
		array $dirMd5List,
	): array
	{
		$row = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField('MESSAGE_COUNT', $this->messageCountField('MESSAGE_COUNT'))
			->registerRuntimeField('LAST_ACTIVITY', $this->newestDateField('UID.INTERNALDATE'))
			->addSelect('MESSAGE_COUNT')
			->addSelect('LAST_ACTIVITY')
			->where('CONVERSATION_ID', $conversationId)
			->fetch()
		;
		$draftCounts = $this->countReplyDrafts($mailboxId, $userId, [$conversationId], $dirMd5List);
		$revisions = $this->findConversationRevisions($mailboxId, [$conversationId]);
		$draftMembershipFingerprints = $this->findConversationDraftMembershipFingerprints(
			$mailboxId,
			$userId,
			[$conversationId],
			$dirMd5List,
		);
		$representatives = $row === false
			? []
			: $this->findRepresentativeMessageIds(
				$mailboxId,
				[[
					'conversationId' => $conversationId,
					'lastActivity' => $row['LAST_ACTIVITY'],
				]],
				$dirMd5List,
			);

		return [
			'messageCount' => $row === false ? 0 : (int)$row['MESSAGE_COUNT'],
			'representativeMessageId' => $representatives[$conversationId] ?? 0,
			'draftCount' => $draftCounts[$conversationId] ?? 0,
			'revision' => $revisions[$conversationId] ?? 0,
			// Compatibility for the group-action service while the version fact keeps its old key.
			'membershipFingerprint' => $revisions[$conversationId] ?? 0,
			'draftMembershipFingerprint' => $draftMembershipFingerprints[$conversationId] ?? '',
		];
	}

	/**
	 * One portion of the messages of the conversation, newest first. A message is placed by the
	 * newest of its rows visible in the scope, so a copy in another folder never splits it in two.
	 *
	 * @param string[] $dirMd5List
	 * @param array{date: DateTime, id: int}|null $cursor position of the previous portion
	 * @return list<array{messageId: int, lastActivity: DateTime}>
	 */
	public function findConversationMessagePage(
		int $mailboxId,
		int $conversationId,
		array $dirMd5List,
		?array $cursor,
		int $limit,
	): array
	{
		$query = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('MESSAGE_ID')
			->registerRuntimeField('LAST_ACTIVITY', $this->newestDateField('UID.INTERNALDATE'))
			->addSelect('LAST_ACTIVITY')
			->where('CONVERSATION_ID', $conversationId)
			->addGroup('MESSAGE_ID')
			->addOrder('LAST_ACTIVITY', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->setLimit($limit)
		;
		if ($cursor !== null)
		{
			$this->applyKeysetBound($query, 'LAST_ACTIVITY', 'MESSAGE_ID', $cursor);
		}

		return array_map(
			static fn(array $row): array => [
				'messageId' => (int)$row['MESSAGE_ID'],
				'lastActivity' => $row['LAST_ACTIVITY'],
			],
			$query->fetchAll(),
		);
	}

	/**
	 * Messages of the conversation the portions have not reached yet.
	 *
	 * @param string[] $dirMd5List
	 * @param array{date: DateTime, id: int}|null $cursor
	 */
	public function countConversationMessagesAfter(
		int $mailboxId,
		int $conversationId,
		array $dirMd5List,
		?array $cursor,
	): int
	{
		$row = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField('ITEM_COUNT', $this->messagesAfterBoundField($cursor))
			->addSelect('ITEM_COUNT')
			->where('CONVERSATION_ID', $conversationId)
			->fetch()
		;

		return $row === false ? 0 : (int)$row['ITEM_COUNT'];
	}

	/**
	 * One portion of the active reply drafts of the user in the conversation, newest first.
	 *
	 * @param string[] $dirMd5List
	 * @param array{date: DateTime, id: int}|null $cursor
	 * @return list<array{draftId: int, updatedAt: DateTime}>
	 */
	public function findConversationDraftPage(
		int $mailboxId,
		int $userId,
		int $conversationId,
		array $dirMd5List,
		?array $cursor,
		int $limit,
	): array
	{
		$query = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField($this->activeDraftReference())
			->addSelect('REPLY_DRAFT.ID', 'DRAFT_ID')
			->addSelect('REPLY_DRAFT.DATE_MODIFY', 'DRAFT_DATE')
			->where('CONVERSATION_ID', $conversationId)
			->where('REPLY_DRAFT.USER_ID', $userId)
			// One draft answers one message, and that message is visible in as many folders as it
			// was copied to.
			->addGroup('REPLY_DRAFT.ID')
			->addGroup('REPLY_DRAFT.DATE_MODIFY')
			->addOrder('REPLY_DRAFT.DATE_MODIFY', 'DESC')
			->addOrder('REPLY_DRAFT.ID', 'DESC')
			->setLimit($limit)
		;
		if ($cursor !== null)
		{
			$this->applyKeysetBound($query, 'REPLY_DRAFT.DATE_MODIFY', 'REPLY_DRAFT.ID', $cursor);
		}

		return array_map(
			static fn(array $row): array => [
				'draftId' => (int)$row['DRAFT_ID'],
				'updatedAt' => $row['DRAFT_DATE'],
			],
			$query->fetchAll(),
		);
	}

	/**
	 * Reply drafts of the conversation the portions have not reached yet.
	 *
	 * @param string[] $dirMd5List
	 * @param array{date: DateTime, id: int}|null $cursor
	 */
	public function countConversationDraftsAfter(
		int $mailboxId,
		int $userId,
		int $conversationId,
		array $dirMd5List,
		?array $cursor,
	): int
	{
		$query = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField($this->activeDraftReference())
			->registerRuntimeField('DRAFT_COUNT', $this->replyDraftCountField())
			->addSelect('DRAFT_COUNT')
			->where('CONVERSATION_ID', $conversationId)
			->where('REPLY_DRAFT.USER_ID', $userId)
		;
		if ($cursor !== null)
		{
			$this->applyKeysetBound($query, 'REPLY_DRAFT.DATE_MODIFY', 'REPLY_DRAFT.ID', $cursor);
		}
		$row = $query->fetch();

		return $row === false ? 0 : (int)$row['DRAFT_COUNT'];
	}

	/**
	 * Folder of the newest visible row of every given message, so a row of the expansion is labelled
	 * by the folder the message is really shown from.
	 *
	 * @param int[] $messageIds
	 * @param string[] $dirMd5List
	 * @return array<int, string> folder hash by message identifier
	 */
	public function findMessageFolders(int $mailboxId, array $messageIds, array $dirMd5List): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('MESSAGE_ID')
			->addSelect('UID.DIR_MD5', 'DIR_MD5')
			->whereIn('MESSAGE_ID', $messageIds)
			->addOrder('UID.INTERNALDATE', 'ASC')
			->fetchAll()
		;

		$folders = [];
		foreach ($rows as $row)
		{
			// Read oldest first: the last row of a message is the newest one it is visible in.
			$folders[(int)$row['MESSAGE_ID']] = (string)$row['DIR_MD5'];
		}

		return $folders;
	}

	/**
	 * Which of the given messages matched the current search or filter.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true>
	 */
	public function findMatchedMessageIds(int $mailboxId, array $messageIds, array $matchedFilter): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MessageConversationMemberTable::query()
			->addSelect('MESSAGE_ID')
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('MESSAGE_ID', $messageIds)
			->whereIn('MESSAGE_ID', $this->matchedMessageIdsQuery($matchedFilter))
			->fetchAll()
		;

		return array_fill_keys(
			array_map(static fn(array $row): int => (int)$row['MESSAGE_ID'], $rows),
			true,
		);
	}

	/**
	 * Lightweight source rows for the mobile message contract. The body is capped in SQL for the
	 * preview; neither the complete body nor headers leave the database.
	 *
	 * @param int[] $messageIds
	 * @param string[] $dirMd5List
	 * @return array<int, array>
	 */
	public function findMessageRows(int $mailboxId, array $messageIds, array $dirMd5List): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$sqlHelper = Application::getConnection()->getSqlHelper();
		$rows = $this->buildScopeQuery($mailboxId, $dirMd5List)
			->registerRuntimeField(
				new Reference(
					'MAILBOX',
					MailboxTable::class,
					Join::on('this.MAILBOX_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->setSelect([
				'MESSAGE_ID',
				'UID_ID' => 'UID.ID',
				'IS_SEEN' => 'UID.IS_SEEN',
				'DIR_MD5' => 'UID.DIR_MD5',
				'INTERNALDATE' => 'UID.INTERNALDATE',
				'MAILBOX_ID',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'MAILBOX_NAME' => 'MAILBOX.NAME',
				'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				'SUBJECT' => 'MESSAGE.SUBJECT',
				'FIELD_FROM' => 'MESSAGE.FIELD_FROM',
				'FIELD_TO' => 'MESSAGE.FIELD_TO',
				'FIELD_CC' => 'MESSAGE.FIELD_CC',
				'FIELD_BCC' => 'MESSAGE.FIELD_BCC',
				'FIELD_REPLY_TO' => 'MESSAGE.FIELD_REPLY_TO',
				'FIELD_DATE' => 'MESSAGE.FIELD_DATE',
				'ATTACHMENTS' => 'MESSAGE.ATTACHMENTS',
				'OPTIONS' => 'MESSAGE.OPTIONS',
				'BODY_PREVIEW',
			])
			->registerRuntimeField(
				new ExpressionField(
					'BODY_PREVIEW',
					$sqlHelper->getSubstrFunction('%s', 1, 200),
					['MESSAGE.BODY'],
				),
			)
			->whereIn('MESSAGE_ID', $messageIds)
			->addOrder('UID.INTERNALDATE', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->fetchAll()
		;

		$messages = [];
		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			if (!isset($messages[$messageId]))
			{
				$messages[$messageId] = $row;

				continue;
			}

			// One message lives in as many uid rows as it has folders; the response keeps one row.
			if (
				!in_array((string)$messages[$messageId]['IS_SEEN'], self::SEEN_STATUSES, true)
				&& in_array((string)$row['IS_SEEN'], self::SEEN_STATUSES, true)
			)
			{
				$messages[$messageId]['IS_SEEN'] = $row['IS_SEEN'];
			}
		}

		return $messages;
	}

	/**
	 * Scope of the grid: members of the mailbox whose message is visible in one of the folders.
	 *
	 * @param string[] $dirMd5List
	 */
	private function buildScopeQuery(int $mailboxId, array $dirMd5List): Query
	{
		return MessageConversationMemberTable::query()
			->registerRuntimeField(
				new Reference(
					'UID',
					MailMessageUidTable::class,
					Join::on('this.MAILBOX_ID', 'ref.MAILBOX_ID')
						->whereColumn('this.MESSAGE_ID', 'ref.MESSAGE_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->setFilter(QueryBuilder::generationScopeFilter([$mailboxId], 'UID.'))
			->where('MAILBOX_ID', $mailboxId)
			->where('UID.DELETE_TIME', 0)
			->whereNotIn('UID.IS_OLD', MailMessageUidTable::HIDDEN_STATUSES)
			->where('UID.MESSAGE_ID', '>', 0)
			->whereNotNull('UID.INTERNALDATE')
			->whereIn('UID.DIR_MD5', $dirMd5List)
		;
	}

	/** @param string[] $dirMd5List */
	private function availableMessageIdsQuery(int $mailboxId, array $dirMd5List): Query
	{
		return $this->buildScopeQuery($mailboxId, $dirMd5List)
			->addSelect('MESSAGE_ID')
			->addGroup('MESSAGE_ID')
		;
	}

	/**
	 * Uid rows of a scope query, in the shape a group action resolves its targets in.
	 *
	 * @return list<array{uidId: string, messageId: int, isSeen: bool}>
	 */
	private function fetchUidRows(Query $query): array
	{
		$rows = $query
			->addSelect('UID.ID', 'UID_ID')
			->addSelect('MESSAGE_ID')
			->addSelect('UID.IS_SEEN', 'IS_SEEN')
			->fetchAll()
		;

		return array_map(
			static fn(array $row): array => [
				'uidId' => (string)$row['UID_ID'],
				'messageId' => (int)$row['MESSAGE_ID'],
				'isSeen' => in_array((string)$row['IS_SEEN'], self::SEEN_STATUSES, true),
			],
			$rows,
		);
	}

	/**
	 * Active reply drafts joined by the message they answer. A new or forwarded draft has no
	 * confirmed parent, so it never joins a conversation.
	 */
	private function activeDraftReference(): Reference
	{
		return new Reference(
			'REPLY_DRAFT',
			DraftTable::class,
			Join::on('this.MESSAGE_ID', 'ref.PARENT_MESSAGE_ID')
				->where('ref.CONTEXT_TYPE', DraftTable::CONTEXT_MAIL)
				->where('ref.COMPOSE_MODE', DraftTable::COMPOSE_MODE_REPLY)
				->where('ref.STATUS', DraftTable::STATUS_ACTIVE)
				->where('ref.DATE_EXPIRE', '>', new DateTime()),
			['join_type' => Join::TYPE_INNER],
		);
	}

	private function newestDateField(string $column): ExpressionField
	{
		return (new ExpressionField('LAST_ACTIVITY', 'MAX(%s)', [$column]))
			->configureValueType(DatetimeField::class)
		;
	}

	/** Available messages of the group: one message is one element however many folders show it. */
	private function messageCountField(string $name): ExpressionField
	{
		return new ExpressionField($name, 'COUNT(DISTINCT %s)', ['MESSAGE_ID']);
	}

	private function representativeIdField(): ExpressionField
	{
		return new ExpressionField('REPRESENTATIVE_ID', 'MAX(%s)', ['MESSAGE_ID']);
	}

	private function replyDraftCountField(): ExpressionField
	{
		return new ExpressionField('DRAFT_COUNT', 'COUNT(DISTINCT %s)', ['REPLY_DRAFT.ID']);
	}

	/**
	 * Messages of the scope the portions have not reached yet. A message is placed by the newest of
	 * its visible rows, so it is still ahead of the bound while any of those rows is: counting the
	 * messages that are and subtracting them keeps the whole set in one statement, without grouping
	 * it by message and counting the groups.
	 *
	 * @param array{date: DateTime, id: int}|null $cursor
	 */
	private function messagesAfterBoundField(?array $cursor): ExpressionField
	{
		if ($cursor === null)
		{
			return $this->messageCountField('ITEM_COUNT');
		}

		return new ExpressionField(
			'ITEM_COUNT',
			sprintf(
				'COUNT(DISTINCT %%s) - COUNT(DISTINCT CASE WHEN %%s > %1$s OR (%%s = %1$s AND %%s >= %2$d)'
					. ' THEN %%s END)',
				Application::getConnection()->getSqlHelper()->convertToDbDateTime($cursor['date']),
				$cursor['id'],
			),
			['MESSAGE_ID', 'UID.INTERNALDATE', 'UID.INTERNALDATE', 'MESSAGE_ID', 'MESSAGE_ID'],
		);
	}

	/**
	 * Unread messages of the group. A message counts as unread while not one of its visible uid rows
	 * carries a seen flag, exactly as the row of the plain list reads them: several folders show one
	 * message, and that row is already read once any of them says so.
	 */
	private function unreadCountField(): ExpressionField
	{
		return new ExpressionField(
			'UNREAD_COUNT',
			sprintf(
				"COUNT(DISTINCT %%s) - COUNT(DISTINCT CASE WHEN %%s IN ('%s') THEN %%s END)",
				implode("', '", self::SEEN_STATUSES),
			),
			['MESSAGE_ID', 'UID.IS_SEEN', 'MESSAGE_ID'],
		);
	}

	/** Conversations holding at least one message of the current search or filter. */
	private function matchedConversationIdsQuery(int $mailboxId, array $matchedFilter): Query
	{
		return MessageConversationMemberTable::query()
			->addSelect('CONVERSATION_ID')
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('MESSAGE_ID', $this->matchedMessageIdsQuery($matchedFilter))
		;
	}

	/**
	 * Messages of the current search or filter, selected by the very builder the plain list uses, so
	 * that both lists answer a filter with the same set of messages.
	 */
	private function matchedMessageIdsQuery(array $matchedFilter): Query
	{
		$query = QueryBuilder::buildMailMessageListQuery($matchedFilter);
		$query->setLimit(null);
		$query->setOffset(null);
		$query->setOrder([]);

		return $query;
	}

	/**
	 * Keyset bound of the page. Both halves of it live in one condition on purpose: the tree holds an
	 * aggregate, so the core moves the whole of it into HAVING, where the grouped column is legal.
	 *
	 * @param array{date: DateTime, id: int} $cursor
	 */
	private function applyKeysetBound(Query $query, string $dateField, string $idField, array $cursor): void
	{
		$query->where(
			Query::filter()
				->logic('or')
				->where($dateField, '<', $cursor['date'])
				->where(
					Query::filter()
						->where($dateField, $cursor['date'])
						->where($idField, '<', $cursor['id']),
				),
		);
	}
}
