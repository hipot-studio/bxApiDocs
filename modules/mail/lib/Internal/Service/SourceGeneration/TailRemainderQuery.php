<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * The remainder of a transfer: letters the retained generation still holds a live
 * placement of while the prepared one holds none.
 *
 * The transfer service copies the mail of the mailbox from one server to the other on
 * its own and does not carry all of it over. A letter it left behind disappears from
 * the mailbox the moment the prepared generation starts serving it, and its
 * attachments become unreachable for good. This is the enumeration of exactly those
 * letters, in the order the append stage has to work through them: first the ones a
 * CRM activity or a task points at, then the rest.
 *
 * Two passes with a cursor of their own instead of one sorted walk. The bindings live
 * in b_mail_message_access, whose index leads with the message, so the priority of a
 * letter is answered for a page of message ids and never sorted over the whole
 * remainder - and a mailbox has no index that would order the bindings of a mailbox
 * by the letter they point at.
 *
 * The answer is placements alone: a letter this stage has already appended stays in
 * the remainder until the reverse synchronization registers its placement of the
 * prepared generation. What has been appended already is the question of the write
 * ahead record of the stage, not of this enumeration.
 */
final class TailRemainderQuery
{
	/** Letters one page of the remainder carries */
	public const PAGE_SIZE = 50;

	/**
	 * Placements one probe of the walk reads per generation of the retained scope. The
	 * page is counted in placements and not in letters: one letter has a placement per
	 * folder it lies in, and a probe must be bounded by what it reads.
	 */
	private const PROBE_SIZE = 200;

	/**
	 * How many letters of the mailbox one call may walk past per letter it is asked for.
	 * A mailbox the service carried over completely has a remainder of nothing, and
	 * finding that out costs a walk over its whole history: the call gives back the
	 * cursor it reached instead of holding the stage until the walk is over.
	 */
	private const SCAN_BUDGET_FACTOR = 20;

	/** The bindings that make a letter of the remainder go first */
	private const PRIORITY_ENTITY_TYPES = [
		MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY,
		MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
	];

	/**
	 * Letters of the remainder a CRM activity or a task points at: the ones whose loss
	 * costs more than a letter, because the deal keeps the text of a letter whose files
	 * are gone.
	 *
	 * @param Context $context The context of the import into the prepared generation.
	 * @param int $afterMessageId The cursor of the pass, 0 starts it.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function listReferencedPage(
		Context $context,
		int $afterMessageId = 0,
		int $limit = self::PAGE_SIZE,
	): TailRemainderPage
	{
		return $this->listPage($context, true, $afterMessageId, $limit);
	}

	/**
	 * The rest of the remainder, walked by a pass and a cursor of its own once the
	 * referenced letters are through. A letter of the first pass never comes back here:
	 * the two passes split the very same page by the very same lookup.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function listUnreferencedPage(
		Context $context,
		int $afterMessageId = 0,
		int $limit = self::PAGE_SIZE,
	): TailRemainderPage
	{
		return $this->listPage($context, false, $afterMessageId, $limit);
	}

	/**
	 * The remainder of the given folders of the retained generation alone, the letters a CRM activity
	 * or a task points at among the rest: the walk a hand over asks for again once it brought those
	 * folders into the new server {@see TailAppendService::reopenForFolders()}.
	 *
	 * The probe asks for every folder of every generation of the scope by a branch of its own, so a
	 * branch reads the placements of that folder alone, by the index of the folder listing
	 * (IX_MAIL_MESSAGE_UID_GEN_LIST), and the walk costs the size of the folders and not the size of
	 * the mailbox; the branches of a probe are one statement.
	 *
	 * @param string[] $folderHashes The path hashes of the folders of the retained generation.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function listFolderPage(
		Context $context,
		array $folderHashes,
		int $afterMessageId = 0,
		int $limit = self::PAGE_SIZE,
	): TailRemainderPage
	{
		return $this->listPage($context, null, $afterMessageId, $limit, array_values(array_unique($folderHashes)));
	}

	/**
	 * @param bool|null $referenced Null takes the letters of both kinds.
	 * @param string[]|null $folders Path hashes the placements are narrowed to, null for all.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function listPage(
		Context $context,
		?bool $referenced,
		int $afterMessageId,
		int $limit,
		?array $folders = null,
	): TailRemainderPage
	{
		$limit = max($limit, 1);
		$cursor = max($afterMessageId, 0);

		/*
			The retained generation is the one still serving the mailbox, so its scope is the
			one of the pointer - and it has to be that shape and not a strict equality: rows
			with the implicit generation 0 are written by code that knows nothing of
			generations and belong to the active source all the same.
		*/
		$retained = GenerationScope::forMailbox($context->mailboxId);
		$prepared = GenerationScope::fromContext($context);

		$found = [];
		$walked = 0;
		$budget = $limit * self::SCAN_BUDGET_FACTOR;

		while ($walked < $budget)
		{
			$probe = $this->probe($context->mailboxId, $retained, $cursor, $folders);
			$candidates = $probe['ids'];
			$walked += max(count($candidates), 1);
			$cursor = $probe['cursor'];

			$placed = $this->findPlacedInPreparedGeneration($context->mailboxId, $prepared, $candidates);
			$prioritized = $referenced === null ? [] : $this->findPrioritizedMessages($context->mailboxId, $candidates);

			foreach ($candidates as $messageId)
			{
				if (isset($placed[$messageId]) || ($referenced !== null && isset($prioritized[$messageId]) !== $referenced))
				{
					continue;
				}

				$found[] = $messageId;

				// The page is full in the middle of a probe: what is left of it belongs to the next one
				if (count($found) >= $limit)
				{
					return new TailRemainderPage($found, $messageId, false);
				}
			}

			if ($probe['isExhausted'])
			{
				return new TailRemainderPage($found, $cursor, true);
			}
		}

		return new TailRemainderPage($found, $cursor, false);
	}

	/**
	 * The letters of the retained generation the walk sees next: alive, linked to a
	 * logical message and beyond the cursor.
	 *
	 * Every generation of the scope is asked for by an equality of its own instead of
	 * one condition over the whole set. The index leads with the mailbox and the
	 * generation, so an equality over both leaves the message id an ordered range and
	 * the page is answered by the index alone; a set of generations makes two ranges of
	 * it, and an open ended range that has to be ordered brings back a sort of
	 * everything the mailbox still holds above the cursor - the walk over the history of
	 * a mailbox would be quadratic. The set itself is never guessed at: it comes from
	 * the scope, and the union of the equalities is the very same condition.
	 *
	 * A walk narrowed to some folders asks for each of them by an equality of its own as well, for
	 * the same reason: the folder follows the generation in the index of the folder listing
	 * (IX_MAIL_MESSAGE_UID_GEN_LIST), and a list of folders would leave the message id unordered.
	 * The branches go to the database as one statement, each of them a limited part of a UNION ALL,
	 * so a probe costs one round trip however many folders and generations it spans.
	 *
	 * @param string[]|null $folders Path hashes the placements are narrowed to, null for all.
	 * @return array{ids: int[], cursor: int, isExhausted: bool}
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function probe(int $mailboxId, GenerationScope $retained, int $cursor, ?array $folders = null): array
	{
		$branches = [];
		foreach ($retained->getGenerationIds() ?? [null] as $generationId)
		{
			foreach ($folders ?? [null] as $folder)
			{
				$branches[] = $this->branchQuery($mailboxId, $generationId, $cursor, $folder, count($branches));
			}
		}

		$query = array_shift($branches);
		if ($branches !== [])
		{
			$query->unionAll(...$branches);
		}

		/** @var array<int, int[]> $byBranch */
		$byBranch = [];
		foreach ($query->exec()->fetchAll() as $row)
		{
			$byBranch[(int)$row['BRANCH']][] = (int)$row['MESSAGE_ID'];
		}

		$messageIds = [];
		// The highest message id every branch of the probe is complete up to
		$bound = null;
		foreach ($byBranch as $branch)
		{
			sort($branch);
			foreach ($branch as $messageId)
			{
				$messageIds[$messageId] = $messageId;
			}

			if (count($branch) >= self::PROBE_SIZE)
			{
				$reached = (int)end($branch);
				$bound = $bound === null ? $reached : min($bound, $reached);
			}
		}

		if ($messageIds === [])
		{
			return ['ids' => [], 'cursor' => $cursor, 'isExhausted' => true];
		}

		ksort($messageIds);

		if ($bound === null)
		{
			return [
				'ids' => array_values($messageIds),
				'cursor' => (int)array_key_last($messageIds),
				'isExhausted' => true,
			];
		}

		/*
			A branch cut short by the probe says nothing about the letters above its last one,
			so the probe ends where the shortest of them does. The letters of a longer branch
			beyond that are dropped and read again by the next probe, never skipped.
		*/
		return [
			'ids' => array_values(array_filter($messageIds, static fn (int $id): bool => $id <= $bound)),
			'cursor' => $bound,
			'isExhausted' => false,
		];
	}

	/**
	 * One branch of a probe: the placements of that generation after the cursor, oldest first,
	 * repeated per placement, each row marked with the number of its branch.
	 *
	 * @param int|null $generationId null asks without a generation condition at all, which is
	 *                              what the unfiltered scope of a mailbox without generations means.
	 * @param string|null $folder The path hash of the one folder asked for, null for every folder.
	 * @throws ArgumentException|SystemException
	 */
	private function branchQuery(int $mailboxId, ?int $generationId, int $cursor, ?string $folder, int $branch): Query
	{
		$query = MailMessageUidTable::query()
			->addSelect('MESSAGE_ID')
			->registerRuntimeField(new ExpressionField('BRANCH', (string)$branch))
			->addSelect('BRANCH')
			->where('MAILBOX_ID', $mailboxId)
			// A placement of no logical message carries no letter to assemble, and the cursor starts at 0
			->where('MESSAGE_ID', '>', $cursor)
			// A letter whose every placement is gone left the mailbox: there is nothing to carry over
			->addFilter('==DELETE_TIME', 0)
			->setOrder(['MESSAGE_ID' => 'ASC'])
			->setLimit(self::PROBE_SIZE)
		;

		if ($generationId !== null)
		{
			$query->addFilter('=GENERATION_ID', $generationId);
		}

		if ($folder !== null)
		{
			$query->addFilter('=DIR_MD5', $folder);
		}

		return $query;
	}

	/**
	 * Letters of the page the prepared generation already holds a placement of - the ones
	 * the transfer service did carry over.
	 *
	 * The deletion mark is not asked about on purpose: a placement of the prepared
	 * generation exists only because the letter reached the new source, and appending it
	 * a second time would leave the mailbox with two copies of it.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function findPlacedInPreparedGeneration(int $mailboxId, GenerationScope $prepared, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => $prepared->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIds,
			]),
		])->fetchAll();

		return $this->indexByMessage($rows);
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, true>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function findPrioritizedMessages(int $mailboxId, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MessageAccessTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIds,
				'@ENTITY_TYPE' => self::PRIORITY_ENTITY_TYPES,
			],
		])->fetchAll();

		return $this->indexByMessage($rows);
	}

	/**
	 * @return array<int, true>
	 */
	private function indexByMessage(array $rows): array
	{
		$found = [];

		foreach ($rows as $row)
		{
			$found[(int)$row['MESSAGE_ID']] = true;
		}

		return $found;
	}
}
