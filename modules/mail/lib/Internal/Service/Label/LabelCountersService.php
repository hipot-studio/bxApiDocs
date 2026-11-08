<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Label;

use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailCounterTable;
use Bitrix\Mail\Internals\MessageLabelTable;
use Bitrix\Mail\Internals\UserLabelTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Per-label unread counters, stored in b_mail_counter as (MAILBOX_ID, 'LABEL', labelId) and always
 * recomputed from the messages.
 */
class LabelCountersService
{
	private const ENTITY_TYPE = 'LABEL';
	private const SEEN_STATUSES = ['Y', 'S'];

	/**
	 * @return array<int, int> labelId => unread summed across the user's mailboxes
	 */
	public function getCountersForUser(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		return $this->getCountersForLabelIds($this->getUserLabelIds($userId));
	}

	/**
	 * @param int[] $labelIds
	 * @return array<int, int> labelId => unread summed across mailboxes
	 */
	public function getCountersForLabelIds(array $labelIds): array
	{
		$labelIds = $this->normalizeIds($labelIds);
		if ($labelIds === [])
		{
			return [];
		}

		return $this->sumByLabelIds($labelIds);
	}

	public function recalculateForLabels(int $mailboxId, array $labelIds): void
	{
		$labelIds = $this->normalizeIds($labelIds);
		if ($mailboxId <= 0 || $labelIds === [])
		{
			return;
		}

		$newCounts = $this->countUnreadByLabel($mailboxId, $labelIds);
		$storedCounts = $this->readStoredCounters($mailboxId, $labelIds);

		$changed = [];
		foreach ($labelIds as $labelId)
		{
			$new = $newCounts[$labelId] ?? 0;
			$old = $storedCounts[$labelId] ?? 0;
			if ($new === $old)
			{
				continue;
			}

			$this->writeCounter($mailboxId, $labelId, $new);
			$changed[] = $labelId;
		}

		if ($changed !== [])
		{
			$this->pushCounters($changed);
		}
	}

	/**
	 * Gated before any query: this runs inside the sync tick of every portal. Never throws.
	 */
	public function recalculateForMessages(int $mailboxId, array $messageIds): void
	{
		if (!LabelsFeature::isEnabled())
		{
			return;
		}

		try
		{
			$messageIds = $this->normalizeIds($messageIds);
			if (!$this->hasBindingsToCorrect($mailboxId, $messageIds))
			{
				return;
			}

			$this->recalculateAttachedLabels($mailboxId, $messageIds);
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
	}

	/**
	 * Gated before any query. Never throws.
	 *
	 * @param string[] $uidRowIds
	 */
	public function recalculateForUidRows(int $mailboxId, array $uidRowIds): void
	{
		if (!LabelsFeature::isEnabled())
		{
			return;
		}

		try
		{
			$uidRowIds = array_values(array_filter($uidRowIds, static fn ($id): bool => (string)$id !== ''));
			if (!$this->hasBindingsToCorrect($mailboxId, $uidRowIds))
			{
				return;
			}

			$this->recalculateAttachedLabels($mailboxId, $this->resolveMessageIds($mailboxId, $uidRowIds));
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
	}

	/**
	 * Gated as recalculate* is: with the feature off nothing here queries the database. Bindings left
	 * from a period when it was on stay as harmless rows - both the label list and its counter join the
	 * uid rows, so a binding of a deleted message surfaces in neither. Never throws.
	 *
	 * @param int[] $messageIds
	 */
	public function handleMessagesDeleted(int $mailboxId, array $messageIds): void
	{
		if (!LabelsFeature::isEnabled())
		{
			return;
		}

		try
		{
			$messageIds = $this->normalizeIds($messageIds);
			if (!$this->hasBindingsToCorrect($mailboxId, $messageIds))
			{
				return;
			}

			$labelIds = $this->findLabelsForMessages($mailboxId, $messageIds);
			if ($labelIds === [])
			{
				return;
			}

			MessageLabelTable::deleteByFilter([
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIds,
			]);

			$this->recalculateForLabels($mailboxId, $labelIds);
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
	}

	/**
	 * Neither gated nor guarded - that belongs to the public entry points.
	 *
	 * @param int[] $messageIds
	 */
	private function recalculateAttachedLabels(int $mailboxId, array $messageIds): void
	{
		if ($messageIds === [])
		{
			return;
		}

		$labelIds = $this->findLabelsForMessages($mailboxId, $messageIds);
		if ($labelIds !== [])
		{
			$this->recalculateForLabels($mailboxId, $labelIds);
		}
	}

	/**
	 * Rules out mailboxes without a single binding, the common case on the sync tick.
	 *
	 * @param array<int|string> $ids
	 */
	private function hasBindingsToCorrect(int $mailboxId, array $ids): bool
	{
		return $mailboxId > 0 && $ids !== [] && $this->mailboxHasBindings($mailboxId);
	}

	/**
	 * @return int[]
	 */
	private function getUserLabelIds(int $userId): array
	{
		$rows = UserLabelTable::getList([
			'select' => ['ID'],
			'filter' => ['=USER_ID' => $userId],
		])->fetchAll();

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * @param int[] $labelIds
	 * @return array<int, int> labelId => summed unread across mailboxes
	 */
	private function sumByLabelIds(array $labelIds): array
	{
		if ($labelIds === [])
		{
			return [];
		}

		$rows = MailCounterTable::query()
			->addSelect('ENTITY_ID')
			->registerRuntimeField('CNT', new ExpressionField('CNT', 'SUM(%s)', ['VALUE']))
			->addSelect('CNT')
			->where('ENTITY_TYPE', self::ENTITY_TYPE)
			->whereIn('ENTITY_ID', array_map('strval', $labelIds))
			->addGroup('ENTITY_ID')
			->fetchAll()
		;

		$counters = [];
		foreach ($rows as $row)
		{
			$counters[(int)$row['ENTITY_ID']] = (int)$row['CNT'];
		}

		return $counters;
	}

	/**
	 * @param int[] $labelIds
	 * @return array<int, int> labelId => unread messages in the mailbox
	 */
	private function countUnreadByLabel(int $mailboxId, array $labelIds): array
	{
		// The label list hides spam and trash (MessageFilter::addLabel), so the badge counts the same rows.
		$excludedDirs = MailboxDirectoryHelper::getSpamAndTrashDirsMd5ForMailboxes([$mailboxId]);

		// Messages carrying one of the labels being recounted. The seen-copies subquery below is narrowed
		// to them: without it every recount walks the whole mailbox, and a mark-as-read of a single letter
		// costs as much as a mailbox of any size.
		$labelledMessages = MessageLabelTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('LABEL_ID', $labelIds)
		;

		// A message has a uid row per folder, so it stays unread until any visible copy is seen.
		$seenMessages = $this->visibleCopiesQuery($mailboxId, $excludedDirs)
			->setSelect(['MESSAGE_ID'])
			->whereIn('IS_SEEN', self::SEEN_STATUSES)
			->whereIn('MESSAGE_ID', $labelledMessages)
		;

		$query = MessageLabelTable::query()
			->registerRuntimeField('UID', new Reference(
				'UID',
				MailMessageUidTable::class,
				Join::on('this.MAILBOX_ID', 'ref.MAILBOX_ID')->whereColumn('this.MESSAGE_ID', 'ref.MESSAGE_ID'),
				['join_type' => Join::TYPE_INNER],
			))
			->setSelect([
				'LABEL_ID',
				new ExpressionField('CNT', 'COUNT(DISTINCT %s)', ['MESSAGE_ID']),
			])
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('LABEL_ID', $labelIds)
			->where('UID.DELETE_TIME', 0)
			->where('UID.MESSAGE_ID', '>', 0)
			->whereNotIn('UID.IS_OLD', MailMessageUidTable::HIDDEN_STATUSES)
			->whereNotIn('MESSAGE_ID', $seenMessages)
			->setGroup(['LABEL_ID'])
		;

		if ($excludedDirs !== [])
		{
			$query->whereNotIn('UID.DIR_MD5', $excludedDirs);
		}

		$this->limitToActiveGeneration($query, $mailboxId, 'UID.');

		$counts = [];
		foreach ($query->fetchAll() as $row)
		{
			$counts[(int)$row['LABEL_ID']] = (int)$row['CNT'];
		}

		return $counts;
	}

	private function visibleCopiesQuery(int $mailboxId, array $excludedDirs): Query
	{
		$query = MailMessageUidTable::query()
			->where('MAILBOX_ID', $mailboxId)
			->where('DELETE_TIME', 0)
			->where('MESSAGE_ID', '>', 0)
			->whereNotIn('IS_OLD', MailMessageUidTable::HIDDEN_STATUSES)
		;

		if ($excludedDirs !== [])
		{
			$query->whereNotIn('DIR_MD5', $excludedDirs);
		}

		$this->limitToActiveGeneration($query, $mailboxId);

		return $query;
	}

	/**
	 * The badge counts what the user sees, and the user is served by the active source generation of
	 * the mailbox: the rows of a generation being prepared by a migration are not on any screen yet,
	 * and the rows a switch left behind are read-only leftovers whose letters are counted through the
	 * placements of the active generation instead. Folder hashes are shared by the generations - one
	 * path, one hash - so nothing else in these queries tells them apart.
	 */
	private function limitToActiveGeneration(Query $query, int $mailboxId, string $fieldPrefix = ''): void
	{
		$generationIds = GenerationScope::forMailbox($mailboxId)->getGenerationIds();

		if ($generationIds !== null)
		{
			$query->whereIn($fieldPrefix . 'GENERATION_ID', $generationIds);
		}
	}

	/**
	 * @param int[] $labelIds
	 * @return array<int, int> labelId => stored value in the mailbox
	 */
	private function readStoredCounters(int $mailboxId, array $labelIds): array
	{
		$rows = MailCounterTable::getList([
			'select' => ['ENTITY_ID', 'VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => self::ENTITY_TYPE,
				'@ENTITY_ID' => array_map('strval', $labelIds),
			],
		])->fetchAll();

		$counters = [];
		foreach ($rows as $row)
		{
			$counters[(int)$row['ENTITY_ID']] = (int)$row['VALUE'];
		}

		return $counters;
	}

	public function deleteForLabel(int $labelId): void
	{
		$this->deleteForLabels([$labelId]);
	}

	/**
	 * @param int[] $labelIds
	 */
	public function deleteForLabels(array $labelIds): void
	{
		$labelIds = $this->normalizeIds($labelIds);
		if ($labelIds === [])
		{
			return;
		}

		Application::getConnection()->queryExecute(sprintf(
			"DELETE FROM %s WHERE ENTITY_TYPE = '%s' AND ENTITY_ID IN (%s)",
			MailCounterTable::getTableName(),
			self::ENTITY_TYPE,
			implode(',', array_map(static fn (int $labelId): string => "'" . $labelId . "'", $labelIds)),
		));
	}

	private function writeCounter(int $mailboxId, int $labelId, int $value): void
	{
		$connection = Application::getConnection();

		if ($value <= 0)
		{
			MailCounterTable::delete([
				'MAILBOX_ID' => $mailboxId,
				'ENTITY_TYPE' => self::ENTITY_TYPE,
				'ENTITY_ID' => (string)$labelId,
			]);

			return;
		}

		$sql = $connection->getSqlHelper()->prepareMerge(
			MailCounterTable::getTableName(),
			['MAILBOX_ID', 'ENTITY_TYPE', 'ENTITY_ID'],
			[
				'MAILBOX_ID' => $mailboxId,
				'ENTITY_TYPE' => self::ENTITY_TYPE,
				'ENTITY_ID' => (string)$labelId,
				'VALUE' => $value,
			],
			['VALUE' => $value],
		);

		$sql = current($sql);
		if ($sql !== false && $sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}

	/**
	 * @param int[] $labelIds
	 */
	private function pushCounters(array $labelIds): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$owners = $this->getLabelOwners($labelIds);
		$totals = $this->sumByLabelIds($labelIds);

		$byOwner = [];
		foreach ($labelIds as $labelId)
		{
			$userId = $owners[$labelId] ?? 0;
			if ($userId <= 0)
			{
				continue;
			}

			$byOwner[$userId][(string)$labelId] = $totals[$labelId] ?? 0;
		}

		if ($byOwner === [])
		{
			return;
		}

		foreach ($byOwner as $userId => $counters)
		{
			\Bitrix\Pull\Event::add((int)$userId, [
				'module_id' => 'mail',
				'command' => 'labelCountersUpdated',
				'params' => ['counters' => $counters],
			]);
		}

		\Bitrix\Pull\Event::send();
	}

	/**
	 * @param int[] $labelIds
	 * @return array<int, int> labelId => ownerUserId
	 */
	private function getLabelOwners(array $labelIds): array
	{
		$rows = UserLabelTable::getList([
			'select' => ['ID', 'USER_ID'],
			'filter' => ['@ID' => $labelIds],
		])->fetchAll();

		$owners = [];
		foreach ($rows as $row)
		{
			$owners[(int)$row['ID']] = (int)$row['USER_ID'];
		}

		return $owners;
	}

	/**
	 * @param int[] $messageIds
	 * @return int[]
	 */
	private function findLabelsForMessages(int $mailboxId, array $messageIds): array
	{
		$rows = MessageLabelTable::getList([
			'select' => ['LABEL_ID'],
			'filter' => ['=MAILBOX_ID' => $mailboxId, '@MESSAGE_ID' => $messageIds],
			'group' => ['LABEL_ID'],
		])->fetchAll();

		return array_map(static fn (array $row): int => (int)$row['LABEL_ID'], $rows);
	}

	/**
	 * @param string[] $uidRowIds
	 * @return int[]
	 */
	private function resolveMessageIds(int $mailboxId, array $uidRowIds): array
	{
		$rows = MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@ID' => $uidRowIds,
				'>MESSAGE_ID' => 0,
			],
		])->fetchAll();

		return array_values(array_unique(array_map(
			static fn (array $row): int => (int)$row['MESSAGE_ID'],
			$rows,
		)));
	}

	private function mailboxHasBindings(int $mailboxId): bool
	{
		return MessageLabelTable::getRow([
			'select' => ['LABEL_ID'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]) !== null;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		$ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);

		return array_values(array_unique($ids));
	}

	private function logException(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}
}
