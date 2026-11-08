<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

use Bitrix\Mail\Internals\MessageClosureTable;
use Bitrix\Mail\Internals\MessageConversationMemberTable;
use Bitrix\Mail\Internals\MessageConversationActivityTable;
use Bitrix\Mail\Internals\MessageConversationTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\Result as OrmResult;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * Storage of the conversation projection: the conversations of a mailbox and the single conversation
 * every message belongs to.
 *
 * Projection reads and writes use explicit limits, so a single indexing call has a fixed upper
 * bound even when a mailbox contains a very large connected component.
 *
 * Failures are reported as {@see ConversationProjectionException}; the caller decides whether they are
 * retried or reported. Writes assume the caller owns the transaction.
 */
class MessageConversationRepository
{
	private const QUERY_BATCH_SIZE = 500;

	/**
	 * Identifiers of the connected closure component of the message, restricted to the mailbox, up to
	 * the explicit limit and in ascending order. Empty when the mailbox stores no such message.
	 *
	 * @return int[]
	 */
	public function findConnectedMessageIds(int $messageId, int $mailboxId, int $limit): array
	{
		if ($limit <= 0 || !$this->isMessageStored($messageId, $mailboxId))
		{
			return [];
		}

		$component = [$messageId => true];
		$frontier = [$messageId];
		while ($frontier !== [] && count($component) < $limit)
		{
			$candidateIds = [];
			foreach (
				$this->findNeighborIds($frontier, array_keys($component), $limit - count($component))
				as $neighborId
			)
			{
				if (!isset($component[$neighborId]))
				{
					$candidateIds[$neighborId] = true;
				}
			}

			$frontier = [];
			foreach (array_chunk(array_keys($candidateIds), self::QUERY_BATCH_SIZE) as $chunk)
			{
				foreach ($this->findStoredMessageIds($chunk, $mailboxId) as $storedId)
				{
					if (!isset($component[$storedId]))
					{
						$component[$storedId] = true;
						$frontier[] = $storedId;
						if (count($component) >= $limit)
						{
							break 3;
						}
					}
				}
			}
		}

		$messageIds = array_keys($component);
		sort($messageIds, SORT_NUMERIC);

		return $messageIds;
	}

	/**
	 * One projected closure neighbour that belongs to another conversation.
	 *
	 * The two indexed directions are queried independently with LIMIT 1, keeping the read bounded.
	 *
	 * @return array{conversationId: int, mailboxId: int}|null
	 */
	public function findAdjacentMembership(
		int $messageId,
		int $mailboxId,
		?int $excludedConversationId,
	): ?array
	{
		foreach ([['MESSAGE_ID', 'PARENT_ID'], ['PARENT_ID', 'MESSAGE_ID']] as [$anchorField, $neighborField])
		{
			$query = MessageClosureTable::query()
				->registerRuntimeField(
					new Reference(
						'MEMBER',
						MessageConversationMemberTable::class,
						Join::on('this.' . $neighborField, 'ref.MESSAGE_ID')
							->where('ref.MAILBOX_ID', $mailboxId),
						['join_type' => Join::TYPE_INNER],
					),
				)
				->setSelect(['CONVERSATION_ID' => 'MEMBER.CONVERSATION_ID', 'MAILBOX_ID' => 'MEMBER.MAILBOX_ID'])
				->where($anchorField, $messageId)
				->setOrder([$neighborField => 'ASC'])
				->setLimit(1)
			;
			if ($excludedConversationId !== null)
			{
				$query->where('MEMBER.CONVERSATION_ID', '!=', $excludedConversationId);
			}

			$row = $query->fetch();
			if ($row !== false)
			{
				return [
					'conversationId' => (int)$row['CONVERSATION_ID'],
					'mailboxId' => (int)$row['MAILBOX_ID'],
				];
			}
		}

		return null;
	}

	/**
	 * A bounded set of projected conversations adjacent to every message of a backfill portion.
	 *
	 * Each closure direction is aggregated to its minimum and maximum conversation identifiers. One
	 * identifier is enough to make progress; two tell the caller that a newly projected message must
	 * be revisited to merge another conversation. Thus even an inconsistent huge component returns a
	 * fixed number of rows per input message.
	 *
	 * @param int[] $messageIds
	 * @return array<int, int[]>
	 */
	public function findAdjacentMembershipCandidates(array $messageIds, int $mailboxId): array
	{
		$messageIds = array_values(array_unique(array_map('intval', $messageIds)));
		if ($messageIds === [])
		{
			return [];
		}

		$candidates = [];
		foreach ([['MESSAGE_ID', 'PARENT_ID'], ['PARENT_ID', 'MESSAGE_ID']] as [$anchorField, $neighborField])
		{
			$rows = MessageClosureTable::query()
				->registerRuntimeField(
					new Reference(
						'MEMBER',
						MessageConversationMemberTable::class,
						Join::on('this.' . $neighborField, 'ref.MESSAGE_ID')
							->where('ref.MAILBOX_ID', $mailboxId),
						['join_type' => Join::TYPE_INNER],
					),
				)
				->setSelect(['ANCHOR_ID' => $anchorField])
				->registerRuntimeField(
					'MIN_CONVERSATION_ID',
					new ExpressionField('MIN_CONVERSATION_ID', 'MIN(%s)', ['MEMBER.CONVERSATION_ID']),
				)
				->addSelect('MIN_CONVERSATION_ID')
				->registerRuntimeField(
					'MAX_CONVERSATION_ID',
					new ExpressionField('MAX_CONVERSATION_ID', 'MAX(%s)', ['MEMBER.CONVERSATION_ID']),
				)
				->addSelect('MAX_CONVERSATION_ID')
				->whereIn($anchorField, $messageIds)
				->addGroup($anchorField)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$messageId = (int)$row['ANCHOR_ID'];
				foreach ([(int)$row['MIN_CONVERSATION_ID'], (int)$row['MAX_CONVERSATION_ID']] as $conversationId)
				{
					if ($conversationId > 0)
					{
						$candidates[$messageId][$conversationId] = true;
					}
				}
			}
		}

		return array_map(static fn(array $ids): array => array_keys($ids), $candidates);
	}

	/**
	 * Closure links inside one bounded backfill portion, keyed symmetrically by message identifier.
	 *
	 * @param int[] $messageIds
	 * @return array<int, int[]>
	 */
	public function findRelationsAmongMessages(array $messageIds): array
	{
		$messageIds = array_values(array_unique(array_map('intval', $messageIds)));
		if ($messageIds === [])
		{
			return [];
		}

		$relations = [];
		$rows = MessageClosureTable::query()
			->setSelect(['MESSAGE_ID', 'PARENT_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->whereIn('PARENT_ID', $messageIds)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			$parentId = (int)$row['PARENT_ID'];
			if ($messageId === $parentId)
			{
				continue;
			}

			$relations[$messageId][$parentId] = true;
			$relations[$parentId][$messageId] = true;
		}

		return array_map(static fn(array $ids): array => array_keys($ids), $relations);
	}

	/** Whether the mailbox still stores the message row itself. */
	public function isMessageStored(int $messageId, int $mailboxId): bool
	{
		return MailMessageTable::query()
			->setSelect(['ID'])
			->where('ID', $messageId)
			->where('MAILBOX_ID', $mailboxId)
			->fetch() !== false
		;
	}

	/**
	 * Current conversation and mailbox of every given message that is a member of a conversation,
	 * keyed by message identifier.
	 *
	 * @param int[] $messageIds
	 * @return array<int, array{conversationId: int, mailboxId: int}>
	 */
	public function findMemberships(array $messageIds): array
	{
		$memberships = [];
		foreach (array_chunk($messageIds, self::QUERY_BATCH_SIZE) as $chunk)
		{
			$rows = MessageConversationMemberTable::query()
				->setSelect(['MESSAGE_ID', 'CONVERSATION_ID', 'MAILBOX_ID'])
				->whereIn('MESSAGE_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$memberships[(int)$row['MESSAGE_ID']] = [
					'conversationId' => (int)$row['CONVERSATION_ID'],
					'mailboxId' => (int)$row['MAILBOX_ID'],
				];
			}
		}

		return $memberships;
	}

	/**
	 * Conversation and mailbox of the message, or null when it belongs to no conversation.
	 *
	 * @return array{conversationId: int, mailboxId: int}|null
	 */
	public function findMembership(int $messageId): ?array
	{
		$row = MessageConversationMemberTable::query()
			->setSelect(['CONVERSATION_ID', 'MAILBOX_ID'])
			->where('MESSAGE_ID', $messageId)
			->fetch()
		;

		return $row === false
			? null
			: ['conversationId' => (int)$row['CONVERSATION_ID'], 'mailboxId' => (int)$row['MAILBOX_ID']];
	}

	/**
	 * Fails unless every given conversation exists and belongs to the mailbox.
	 *
	 * @param int[] $conversationIds
	 * @throws ConversationProjectionException
	 */
	public function assertConversationsBelongToMailbox(array $conversationIds, int $mailboxId): void
	{
		$conversationIds = array_values(array_unique($conversationIds));
		if ($conversationIds === [])
		{
			return;
		}

		$found = [];
		foreach (array_chunk($conversationIds, self::QUERY_BATCH_SIZE) as $chunk)
		{
			$rows = MessageConversationTable::query()
				->setSelect(['ID', 'MAILBOX_ID'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				if ((int)$row['MAILBOX_ID'] !== $mailboxId)
				{
					throw new ConversationProjectionException('A conversation cannot be merged across mailboxes.');
				}
				$found[(int)$row['ID']] = true;
			}
		}

		if (count($found) !== count($conversationIds))
		{
			throw new ConversationProjectionException('A projected conversation does not exist.');
		}
	}

	/**
	 * Adds an empty conversation of the mailbox and returns its identifier.
	 *
	 * @throws ConversationProjectionException
	 */
	public function createConversation(int $mailboxId, DateTime $now): int
	{
		$result = MessageConversationTable::add([
			'MAILBOX_ID' => $mailboxId,
			'DATE_CREATE' => $now,
			'REVISION' => 1,
		]);
		$this->assertWritten($result);

		return (int)$result->getId();
	}

	/**
	 * Makes every given message a member of the conversation and returns how many member rows that
	 * actually wrote. A message that already belongs to the conversation is left untouched, so a
	 * repeated projection of an unchanged component writes nothing.
	 *
	 * @param int[] $messageIds
	 * @return int Number of written member rows.
	 * @throws ConversationProjectionException
	 */
	public function upsertMembers(array $messageIds, int $conversationId, int $mailboxId, DateTime $now): int
	{
		return $this->upsertMembersWithKnownMemberships(
			$messageIds,
			$conversationId,
			$mailboxId,
			$now,
			$this->findMemberships($messageIds),
		);
	}

	/**
	 * Writes members whose current placement was loaded with the whole backfill portion.
	 *
	 * @param int[] $messageIds
	 * @param array<int, array{conversationId: int, mailboxId: int}> $memberships
	 * @return int Number of written member rows.
	 * @throws ConversationProjectionException
	 */
	public function upsertMembersWithKnownMemberships(
		array $messageIds,
		int $conversationId,
		int $mailboxId,
		DateTime $now,
		array $memberships,
	): int
	{
		$writtenCount = 0;
		foreach ($messageIds as $messageId)
		{
			$membership = $memberships[$messageId] ?? null;
			if ($membership === null)
			{
				$this->assertWritten(MessageConversationMemberTable::add([
					'MESSAGE_ID' => $messageId,
					'CONVERSATION_ID' => $conversationId,
					'MAILBOX_ID' => $mailboxId,
					'DATE_UPDATE' => $now,
				]));
				$writtenCount++;

				continue;
			}

			if ($membership['mailboxId'] !== $mailboxId)
			{
				throw new ConversationProjectionException(
					'A message cannot belong to conversations from different mailboxes.',
				);
			}
			if ($membership['conversationId'] === $conversationId)
			{
				continue;
			}

			$this->assertWritten(MessageConversationMemberTable::update($messageId, [
				'CONVERSATION_ID' => $conversationId,
				'DATE_UPDATE' => $now,
			]));
			$writtenCount++;
		}

		return $writtenCount;
	}

	/**
	 * Moves one bounded portion of source members into the target conversation.
	 *
	 * @return array{moved: int, exhausted: bool, messageIds: int[]}
	 * @throws ConversationProjectionException
	 */
	public function moveConversationMembers(
		int $sourceConversationId,
		int $targetConversationId,
		int $mailboxId,
		DateTime $now,
		int $limit,
	): array
	{
		$movedCount = 0;
		$rows = MessageConversationMemberTable::query()
			->setSelect(['MESSAGE_ID', 'MAILBOX_ID'])
			->where('CONVERSATION_ID', $sourceConversationId)
			->setOrder(['MESSAGE_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			if ((int)$row['MAILBOX_ID'] !== $mailboxId)
			{
				throw new ConversationProjectionException(
					'A conversation contains a member from another mailbox.',
				);
			}
			$this->assertWritten(MessageConversationMemberTable::update($messageId, [
				'CONVERSATION_ID' => $targetConversationId,
				'DATE_UPDATE' => $now,
			]));
			$movedCount++;
		}

		return [
			'moved' => $movedCount,
			'exhausted' => count($rows) < $limit,
			'messageIds' => array_map(static fn(array $row): int => (int)$row['MESSAGE_ID'], $rows),
		];
	}

	/**
	 * Deletes the conversation when it has no members left.
	 *
	 * @return bool Whether the conversation was deleted.
	 * @throws ConversationProjectionException
	 */
	public function deleteConversationIfEmpty(int $conversationId, int $mailboxId): bool
	{
		$member = MessageConversationMemberTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('CONVERSATION_ID', $conversationId)
			->setLimit(1)
			->fetch()
		;
		if ($member !== false)
		{
			return false;
		}

		MessageConversationActivityTable::deleteByFilter(
			Query::filter()
				->where('MAILBOX_ID', $mailboxId)
				->where('CONVERSATION_ID', $conversationId),
		);
		$this->assertWritten(MessageConversationTable::delete($conversationId));

		return true;
	}

	/**
	 * Advances the version of every affected conversation in one atomic statement.
	 *
	 * @param int[] $conversationIds
	 * @throws ConversationProjectionException
	 */
	public function incrementConversationRevisions(array $conversationIds, int $mailboxId): void
	{
		$conversationIds = array_values(array_unique(array_filter(
			array_map('intval', $conversationIds),
			static fn(int $conversationId): bool => $conversationId > 0,
		)));
		if ($conversationIds === [])
		{
			return;
		}

		$entity = MessageConversationTable::getEntity();
		$connection = $entity->getConnection();
		[$update, $binds] = $connection->getSqlHelper()->prepareUpdate(
			$entity->getDbTableName(),
			['REVISION' => new SqlExpression('?# + 1', 'REVISION')],
		);
		$filter = Query::buildFilterSql(
			$entity,
			Query::filter()
				->where('MAILBOX_ID', $mailboxId)
				->whereIn('ID', $conversationIds),
		);

		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$update,
			$filter,
		), $binds);
		if ($connection->getAffectedRowsCount() !== count($conversationIds))
		{
			throw new ConversationProjectionException('An affected conversation no longer exists.');
		}
	}

	/**
	 * Drops the membership of the message.
	 *
	 * @throws ConversationProjectionException
	 */
	public function deleteMember(int $messageId): void
	{
		$this->assertWritten(MessageConversationMemberTable::delete($messageId));
	}

	/**
	 * A bounded portion of conversation member identifiers, in ascending order.
	 *
	 * @return int[]
	 * @throws ConversationProjectionException
	 */
	public function findConversationMessageIds(int $conversationId, int $mailboxId, int $limit): array
	{
		$rows = MessageConversationMemberTable::query()
			->setSelect(['MESSAGE_ID', 'MAILBOX_ID'])
			->where('CONVERSATION_ID', $conversationId)
			->setOrder(['MESSAGE_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;
		$messageIds = [];
		foreach ($rows as $row)
		{
			if ((int)$row['MAILBOX_ID'] !== $mailboxId)
			{
				throw new ConversationProjectionException(
					'A conversation contains a member from another mailbox.',
				);
			}
			$messageIds[] = (int)$row['MESSAGE_ID'];
		}

		return $messageIds;
	}

	/**
	 * Sort key of the newest message of the set: the server internal date when the mailbox has one,
	 * otherwise the date of the message itself. Ties are broken by the smallest message identifier.
	 *
	 * @param int[] $messageIds
	 * @return array{timestamp: int, messageId: int}
	 */
	public function findNewestMessageKey(array $messageIds, int $mailboxId): array
	{
		$newestTimestamp = PHP_INT_MIN;
		$newestMessageId = PHP_INT_MAX;
		foreach (array_chunk($messageIds, self::QUERY_BATCH_SIZE) as $chunk)
		{
			$internalDates = $this->findInternalDates($chunk, $mailboxId);
			$rows = MailMessageTable::query()
				->setSelect(['ID', 'FIELD_DATE', 'DATE_INSERT'])
				->where('MAILBOX_ID', $mailboxId)
				->whereIn('ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$messageId = (int)$row['ID'];
				$date = $row['FIELD_DATE'] instanceof DateTime ? $row['FIELD_DATE'] : $row['DATE_INSERT'];
				$timestamp = $internalDates[$messageId]
					?? ($date instanceof DateTime ? $date->getTimestamp() : PHP_INT_MIN);
				if (
					$timestamp > $newestTimestamp
					|| ($timestamp === $newestTimestamp && $messageId < $newestMessageId)
				)
				{
					$newestTimestamp = $timestamp;
					$newestMessageId = $messageId;
				}
			}
		}

		return ['timestamp' => $newestTimestamp, 'messageId' => $newestMessageId];
	}

	/**
	 * Drops the whole projection of the mailbox. All tables are cleared by a filter, so the size of
	 * the mailbox does not turn the deletion into a statement per row.
	 */
	public function deleteMailboxProjection(int $mailboxId): void
	{
		$this->deleteByMailbox(MessageConversationActivityTable::class, $mailboxId);
		$this->deleteByMailbox(MessageConversationMemberTable::class, $mailboxId);
		$this->deleteByMailbox(MessageConversationTable::class, $mailboxId);
	}

	/**
	 * Starts a full mailbox activity rebuild without touching conversation memberships. Grouped reads
	 * are disabled while the background pass runs, so its following bounded portions may repopulate
	 * the activity rows incrementally.
	 */
	public function resetMailboxActivities(int $mailboxId): void
	{
		MessageConversationActivityTable::deleteByFilter(
			Query::filter()->where('MAILBOX_ID', $mailboxId),
		);
	}

	/**
	 * Drops one conversation projection so the background pass can rebuild its components.
	 *
	 * @throws ConversationProjectionException
	 */
	public function deleteConversationProjection(int $conversationId, int $mailboxId): void
	{
		$this->assertConversationsBelongToMailbox([$conversationId], $mailboxId);
		MessageConversationMemberTable::deleteByFilter(
			Query::filter()
				->where('MAILBOX_ID', $mailboxId)
				->where('CONVERSATION_ID', $conversationId),
		);
		MessageConversationActivityTable::deleteByFilter(
			Query::filter()
				->where('MAILBOX_ID', $mailboxId)
				->where('CONVERSATION_ID', $conversationId),
		);
		$this->assertWritten(MessageConversationTable::delete($conversationId));
	}

	/**
	 * Replaces folder and all-synchronized activity rows of the affected conversations.
	 *
	 * The caller owns the transaction. Deleting before inserting is safe because readers either see
	 * the old committed rows or the complete replacement.
	 *
	 * @param int[] $conversationIds
	 * @throws ConversationProjectionException
	 */
	public function refreshConversationActivities(array $conversationIds, int $mailboxId): void
	{
		$conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));
		if ($conversationIds === [])
		{
			return;
		}

		MessageConversationActivityTable::deleteByFilter(
			Query::filter()
				->where('MAILBOX_ID', $mailboxId)
				->whereIn('CONVERSATION_ID', $conversationIds),
		);

		$listedFolderHashes = $this->findListedFolderHashes($mailboxId);
		$query = MailMessageUidTable::query()
			->registerRuntimeField(
				new Reference(
					'MEMBER',
					MessageConversationMemberTable::class,
					Join::on('this.MESSAGE_ID', 'ref.MESSAGE_ID')
						->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->setSelect(['CONVERSATION_ID' => 'MEMBER.CONVERSATION_ID', 'DIR_MD5'])
			->registerRuntimeField(
				'LAST_ACTIVITY',
				(new ExpressionField('LAST_ACTIVITY', 'MAX(%s)', ['INTERNALDATE']))
					->configureValueType(DatetimeField::class),
			)
			->addSelect('LAST_ACTIVITY')
			->setFilter(GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@MEMBER.CONVERSATION_ID' => $conversationIds,
				...QueryBuilder::VISIBLE_UID_FILTERS_DRIVER,
				'!=INTERNALDATE' => null,
			]))
			->addGroup('MEMBER.CONVERSATION_ID')
			->addGroup('DIR_MD5')
		;

		$rows = [];
		$allSyncActivities = [];
		foreach ($query->fetchAll() as $row)
		{
			$conversationId = (int)$row['CONVERSATION_ID'];
			$dirMd5 = (string)$row['DIR_MD5'];
			$lastActivity = $row['LAST_ACTIVITY'];
			if (!$lastActivity instanceof DateTime)
			{
				continue;
			}

			$rows[] = [
				'MAILBOX_ID' => $mailboxId,
				'SCOPE_KEY' => MessageConversationActivityTable::folderScope($dirMd5),
				'CONVERSATION_ID' => $conversationId,
				'LAST_ACTIVITY' => $lastActivity,
			];
			if (isset($listedFolderHashes[$dirMd5]))
			{
				$current = $allSyncActivities[$conversationId] ?? null;
				if (!$current instanceof DateTime || $lastActivity > $current)
				{
					$allSyncActivities[$conversationId] = $lastActivity;
				}
			}
		}

		foreach ($allSyncActivities as $conversationId => $lastActivity)
		{
			$rows[] = [
				'MAILBOX_ID' => $mailboxId,
				'SCOPE_KEY' => MessageConversationActivityTable::ALL_SYNC_SCOPE,
				'CONVERSATION_ID' => $conversationId,
				'LAST_ACTIVITY' => $lastActivity,
			];
		}

		if ($rows !== [])
		{
			$this->assertWritten(MessageConversationActivityTable::addMulti($rows, true));
		}
	}

	/**
	 * Advances activity rows using only the affected messages. This is the hot indexing path: adding a
	 * message or moving a bounded merge portion must not rescan every member already in the conversation.
	 *
	 * @param int[] $messageIds
	 * @throws ConversationProjectionException
	 */
	public function updateConversationActivitiesForMessages(array $messageIds, int $mailboxId): void
	{
		$messageIds = array_values(array_unique(array_filter(
			array_map('intval', $messageIds),
			static fn(int $messageId): bool => $messageId > 0,
		)));
		if ($messageIds === [])
		{
			return;
		}

		$query = MailMessageUidTable::query()
			->registerRuntimeField(
				new Reference(
					'MEMBER',
					MessageConversationMemberTable::class,
					Join::on('this.MESSAGE_ID', 'ref.MESSAGE_ID')
						->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->setSelect(['CONVERSATION_ID' => 'MEMBER.CONVERSATION_ID', 'DIR_MD5'])
			->registerRuntimeField(
				'LAST_ACTIVITY',
				(new ExpressionField('LAST_ACTIVITY', 'MAX(%s)', ['INTERNALDATE']))
					->configureValueType(DatetimeField::class),
			)
			->addSelect('LAST_ACTIVITY')
			->setFilter(GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIds,
				...QueryBuilder::VISIBLE_UID_FILTERS_DRIVER,
				'!=INTERNALDATE' => null,
			]))
			->addGroup('MEMBER.CONVERSATION_ID')
			->addGroup('DIR_MD5')
		;

		$folderRows = $query->fetchAll();
		if ($folderRows === [])
		{
			return;
		}
		$listedFolderHashes = $this->findListedFolderHashes(
			$mailboxId,
			array_column($folderRows, 'DIR_MD5'),
		);
		$activities = [];
		foreach ($folderRows as $row)
		{
			$conversationId = (int)$row['CONVERSATION_ID'];
			$dirMd5 = (string)$row['DIR_MD5'];
			$lastActivity = $row['LAST_ACTIVITY'];
			if (!$lastActivity instanceof DateTime)
			{
				continue;
			}

			$this->advanceConversationActivity(
				$mailboxId,
				$conversationId,
				MessageConversationActivityTable::folderScope($dirMd5),
				$lastActivity,
			);
			if (isset($listedFolderHashes[$dirMd5]))
			{
				$current = $activities[$conversationId] ?? null;
				if (!$current instanceof DateTime || $lastActivity > $current)
				{
					$activities[$conversationId] = $lastActivity;
				}
			}
		}

		foreach ($activities as $conversationId => $lastActivity)
		{
			$this->advanceConversationActivity(
				$mailboxId,
				$conversationId,
				MessageConversationActivityTable::ALL_SYNC_SCOPE,
				$lastActivity,
			);
		}
		MessageConversationActivityTable::cleanCache();
	}

	/** @return array<string, true> */
	private function findListedFolderHashes(int $mailboxId, array $dirMd5List = []): array
	{
		$query = MailboxDirectoryTable::query()
			->setSelect(['DIR_MD5', 'IS_SPAM', 'IS_TRASH'])
			->setFilter(GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=IS_SYNC' => MailboxDirectoryTable::ACTIVE,
			]))
		;
		$dirMd5List = array_values(array_unique(array_filter(array_map('strval', $dirMd5List))));
		if ($dirMd5List !== [])
		{
			$query->whereIn('DIR_MD5', $dirMd5List);
		}
		$rows = $query->fetchAll();

		$hashes = [];
		foreach ($rows as $row)
		{
			if (
				(int)$row['IS_SPAM'] !== MailboxDirectoryTable::ACTIVE
				&& (int)$row['IS_TRASH'] !== MailboxDirectoryTable::ACTIVE
			)
			{
				$hashes[(string)$row['DIR_MD5']] = true;
			}
		}

		return $hashes;
	}

	private function advanceConversationActivity(
		int $mailboxId,
		int $conversationId,
		string $scopeKey,
		DateTime $lastActivity,
	): void
	{
		$entity = MessageConversationActivityTable::getEntity();
		$connection = $entity->getConnection();
		$sql = $connection->getSqlHelper()->prepareMerge(
			$entity->getDbTableName(),
			$entity->getPrimaryArray(),
			[
				'MAILBOX_ID' => $mailboxId,
				'CONVERSATION_ID' => $conversationId,
				'SCOPE_KEY' => $scopeKey,
				'LAST_ACTIVITY' => $lastActivity,
			],
			[
				'LAST_ACTIVITY' => new SqlExpression(
					'CASE WHEN ?# < ? THEN ? ELSE ?# END',
					'LAST_ACTIVITY',
					$lastActivity,
					$lastActivity,
					'LAST_ACTIVITY',
				),
			],
		);
		$sql = current($sql);
		if ($sql === false || $sql === '')
		{
			throw new ConversationProjectionException('A conversation activity could not be updated.');
		}

		$connection->queryExecute($sql);
	}

	/**
	 * One portion of the messages of the mailbox after the cursor, in ascending order. This is the
	 * order the backfill walks the mailbox in, so the cursor alone is enough to continue a stopped
	 * pass.
	 *
	 * @return int[]
	 */
	public function findMessageIdsAfter(int $mailboxId, int $afterMessageId, int $limit): array
	{
		$rows = MailMessageTable::query()
			->setSelect(['ID'])
			->where('MAILBOX_ID', $mailboxId)
			->where('ID', '>', $afterMessageId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'ID'));
	}

	/** Messages of the mailbox the projection does not hold yet: the lag of the backfill. */
	public function countUnprojectedMessages(int $mailboxId): int
	{
		return $this->fetchCount(
			MailMessageTable::query()
				->where('MAILBOX_ID', $mailboxId)
				->whereNotIn('ID', $this->projectedMessageIdsQuery($mailboxId)),
			'ID',
		);
	}

	/**
	 * One portion of the members of the mailbox whose message row is gone, in ascending order. Such a
	 * member outlives its message on every deletion path the projection is not told about.
	 *
	 * @return int[]
	 */
	public function findStaleMemberMessageIds(int $mailboxId, int $limit): array
	{
		$rows = MessageConversationMemberTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('MAILBOX_ID', $mailboxId)
			->whereNotIn('MESSAGE_ID', $this->storedMessageIdsQuery($mailboxId))
			->setOrder(['MESSAGE_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'MESSAGE_ID'));
	}

	/**
	 * One portion of the conversations of the mailbox that have no members left, in ascending order.
	 *
	 * Such a conversation can never gain a member again: a member is only ever written into a
	 * conversation that already has one, or into a conversation created in the very same transaction.
	 *
	 * @return int[]
	 */
	public function findEmptyConversationIds(int $mailboxId, int $limit): array
	{
		$rows = MessageConversationTable::query()
			->setSelect(['ID'])
			->where('MAILBOX_ID', $mailboxId)
			->whereNotIn('ID', $this->usedConversationIdsQuery($mailboxId))
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'ID'));
	}

	/** Members of the mailbox whose message row is gone. */
	public function countStaleMembers(int $mailboxId): int
	{
		return $this->fetchCount(
			MessageConversationMemberTable::query()
				->where('MAILBOX_ID', $mailboxId)
				->whereNotIn('MESSAGE_ID', $this->storedMessageIdsQuery($mailboxId)),
			'MESSAGE_ID',
		);
	}

	/**
	 * Conversations of the mailbox holding at least one message without a live uid row. The list shows
	 * such a conversation as incomplete: it is projected correctly, part of it is simply not
	 * synchronized yet, so this number reports the state of the synchronization and not a defect of
	 * the projection.
	 */
	public function countIncompleteConversations(int $mailboxId): int
	{
		$row = MessageConversationMemberTable::query()
			->where('MAILBOX_ID', $mailboxId)
			->whereNotIn('MESSAGE_ID', $this->liveUidMessageIdsQuery($mailboxId))
			->registerRuntimeField(
				'CNT',
				new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'CONVERSATION_ID'),
			)
			->addSelect('CNT')
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	/**
	 * What the projection of the mailbox still holds. Zero on both counters is what confirms a
	 * completed cleanup.
	 *
	 * @return array{members: int, conversations: int}
	 */
	public function countProjectionRows(int $mailboxId): array
	{
		return [
			'members' => $this->fetchCount(
				MessageConversationMemberTable::query()->where('MAILBOX_ID', $mailboxId),
				'MESSAGE_ID',
			),
			'conversations' => $this->fetchCount(
				MessageConversationTable::query()->where('MAILBOX_ID', $mailboxId),
				'ID',
			),
		];
	}

	/**
	 * Closure neighbours of the whole frontier at once: both directions of the closure are read for
	 * batches of vertices, so walking a component costs queries per batch and not per vertex.
	 *
	 * @param int[] $messageIds
	 * @param int[] $excludedMessageIds
	 * @return int[]
	 */
	private function findNeighborIds(array $messageIds, array $excludedMessageIds, int $limit): array
	{
		$neighbors = [];
		foreach ([['MESSAGE_ID', 'PARENT_ID'], ['PARENT_ID', 'MESSAGE_ID']] as [$filterField, $selectField])
		{
			foreach (array_chunk($messageIds, self::QUERY_BATCH_SIZE) as $chunk)
			{
				$query = MessageClosureTable::query()
					->setSelect([$selectField])
					->whereIn($filterField, $chunk)
					->setGroup([$selectField])
					->setOrder([$selectField => 'ASC'])
					->setLimit($limit - count($neighbors))
				;
				$excludedIds = [...$excludedMessageIds, ...array_keys($neighbors)];
				if ($excludedIds !== [])
				{
					$query->whereNotIn($selectField, $excludedIds);
				}
				$rows = $query->fetchAll();
				foreach ($rows as $row)
				{
					$neighbors[(int)$row[$selectField]] = true;
					if (count($neighbors) >= $limit)
					{
						break 3;
					}
				}
			}
		}

		return array_keys($neighbors);
	}

	/**
	 * @param int[] $messageIds
	 * @return int[]
	 */
	public function findStoredMessageIds(array $messageIds, int $mailboxId): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		return array_map(
			'intval',
			array_column(
				MailMessageTable::query()
					->setSelect(['ID'])
					->where('MAILBOX_ID', $mailboxId)
					->whereIn('ID', $messageIds)
					->fetchAll(),
				'ID',
			),
		);
	}

	/**
	 * Drops the given members of one mailbox in one statement.
	 *
	 * @param int[] $messageIds
	 */
	public function deleteMembers(array $messageIds, int $mailboxId): void
	{
		if ($messageIds === [])
		{
			return;
		}

		MessageConversationMemberTable::deleteByFilter(
			Query::filter()
				->where('MAILBOX_ID', $mailboxId)
				->whereIn('MESSAGE_ID', $messageIds),
		);
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, int>
	 */
	private function findInternalDates(array $messageIds, int $mailboxId): array
	{
		$scope = GenerationScope::forMailbox($mailboxId);
		$internalDates = [];
		$lastUidId = '';
		do
		{
			$rows = MailMessageUidTable::query()
				->setSelect(['ID', 'MESSAGE_ID', 'INTERNALDATE'])
				->setFilter($scope->apply([
					'=MAILBOX_ID' => $mailboxId,
					'@MESSAGE_ID' => $messageIds,
					'>ID' => $lastUidId,
				]))
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::QUERY_BATCH_SIZE)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$lastUidId = (string)$row['ID'];
				if (!$row['INTERNALDATE'] instanceof DateTime)
				{
					continue;
				}
				$messageId = (int)$row['MESSAGE_ID'];
				$internalDates[$messageId] = max(
					$internalDates[$messageId] ?? PHP_INT_MIN,
					$row['INTERNALDATE']->getTimestamp(),
				);
			}
		}
		while (count($rows) === self::QUERY_BATCH_SIZE);

		return $internalDates;
	}

	/** Messages of the mailbox that already are a member of a conversation. */
	private function projectedMessageIdsQuery(int $mailboxId): Query
	{
		return MessageConversationMemberTable::query()
			->addSelect('MESSAGE_ID')
			->where('MAILBOX_ID', $mailboxId)
		;
	}

	/** Conversations of the mailbox that still have at least one member. */
	private function usedConversationIdsQuery(int $mailboxId): Query
	{
		return MessageConversationMemberTable::query()
			->addSelect('CONVERSATION_ID')
			->where('MAILBOX_ID', $mailboxId)
		;
	}

	/** Messages the mailbox still stores. */
	private function storedMessageIdsQuery(int $mailboxId): Query
	{
		return MailMessageTable::query()
			->addSelect('ID')
			->where('MAILBOX_ID', $mailboxId)
		;
	}

	/** Messages of the mailbox that still have a uid row not marked as deleted. */
	private function liveUidMessageIdsQuery(int $mailboxId): Query
	{
		return MailMessageUidTable::query()
			->addSelect('MESSAGE_ID')
			->where('MAILBOX_ID', $mailboxId)
			->where('DELETE_TIME', 0)
			->where('MESSAGE_ID', '>', 0)
		;
	}

	private function fetchCount(Query $query, string $countedField): int
	{
		$row = $query
			->registerRuntimeField('CNT', new ExpressionField('CNT', 'COUNT(%s)', $countedField))
			->addSelect('CNT')
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	/** @param class-string<\Bitrix\Main\ORM\Data\DataManager> $tableClass */
	private function deleteByMailbox(string $tableClass, int $mailboxId): void
	{
		$connection = Application::getConnection();
		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE %s',
			$connection->getSqlHelper()->quote($tableClass::getTableName()),
			Query::buildFilterSql($tableClass::getEntity(), Query::filter()->where('MAILBOX_ID', $mailboxId)),
		));
	}

	/** @throws ConversationProjectionException */
	private function assertWritten(OrmResult $result): void
	{
		if (!$result->isSuccess())
		{
			throw new ConversationProjectionException(implode(', ', $result->getErrorMessages()));
		}
	}
}
