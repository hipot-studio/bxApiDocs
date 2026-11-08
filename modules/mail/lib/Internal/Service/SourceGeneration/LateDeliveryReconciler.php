<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CandidateProvider;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CanonicalMessageData;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CanonicalMessageNormalizer;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MatchDecision;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MessageMatcher;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Internals\MessageFingerprintTable;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Mail\MailMessageUidTable;

/**
 * The letters the active source delivered while the transfer was already deciding about them.
 *
 * The matching answers by the fingerprints of the local history, and that history keeps growing
 * under it: the main load of the import holds no sync lock of the mailbox, so the active source
 * goes on delivering - with its filters, its event, its CRM activity and its notifications - and
 * a letter delivered after the fingerprints of a pass were completed carries none of them yet.
 * The import then finds no candidate for the copy of that very letter on the new source, writes
 * down a terminal NEW and creates a second logical record of it. That record is what the user
 * would see after the switch: the same letter, without the CRM activity, the calendar event and
 * the chat of the one they were notified about. Nothing else links the two copies - the header
 * hash of the old synchronization cannot: it is taken over the header block together with the
 * stamp the server put on the letter and its size on the wire, and two sources agree on none of
 * the three.
 *
 * This pass takes such a decision back, and it runs where the boundary with the delivery is
 * finally consistent: inside the hand over, under the sync lock, after the final synchronization
 * of the active source and after the fingerprints have been completed over everything it
 * delivered {@see MigrationService::handOver()}.
 *
 * The walk goes over the letters of the mailbox and not over the journal of the import, and that
 * is the whole reason its cursor can be trusted. Message ids only grow, so a letter delivered
 * between two attempts of a hand over lands beyond every page already walked and is met by the
 * next one. Walking the journal instead would leave exactly the opposite: a decision the cursor
 * has already passed, about a letter that arrived afterwards, would never be looked at again.
 *
 * Only a NEW decision that found no candidate at all is reconsidered, and only for a letter no
 * AMBIGUOUS decision of this generation names. An ambiguous refusal saw its candidates and
 * refused them deliberately; it is never overruled here, where there is less to go on than the
 * import had.
 *
 * Nothing is merged by content and no binding is ever moved: the placements of the prepared
 * generation start serving the delivered letter, and the record the import created - which
 * carries no binding of anyone, because an import publishes no delivery effect - is dropped.
 */
final class LateDeliveryReconciler
{
	/** Letters of the mailbox one page of the walk asks about */
	public const PAGE_SIZE = 500;

	private readonly MigrationMessageImporter $importer;

	public function __construct(
		private readonly MessageMatcher $matcher = new MessageMatcher(),
		?MigrationMessageImporter $importer = null,
		private readonly CandidateProvider $candidates = new CandidateProvider(),
	)
	{
		$this->importer = $importer ?? new MigrationMessageImporter($matcher);
	}

	/**
	 * One bounded page of the letters of the mailbox.
	 *
	 * @param int $afterMessageId The letter the previous page stopped at, 0 starts the walk.
	 * @return int The letter the next page continues after, 0 when the mailbox is covered.
	 */
	public function reconcile(Context $context, int $afterMessageId = 0, int $limit = self::PAGE_SIZE): int
	{
		$page = $this->candidates->listMessageIds($context->mailboxId, max($afterMessageId, 0), max($limit, 1));
		if ($page === [])
		{
			return 0;
		}

		/*
			A letter this import has already answered for is not a delivery of the active source and
			answers for nothing here: either the import created it, or the import matched it - and a
			letter it matched carries the placement of the new generation already.
		*/
		$delivered = $this->withoutLettersOfThisImport($context, $page);

		foreach ($this->findImportedTwins($context, $delivered) as $deliveredMessageId => $twin)
		{
			$this->reunite($context, $twin, $deliveredMessageId);
		}

		return (int)end($page);
	}

	/**
	 * @param int[] $messageIds
	 * @return int[] The ones no result of this generation names.
	 */
	private function withoutLettersOfThisImport(Context $context, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$named = $this->journalRowsOf($context, $messageIds);

		return array_values(array_filter(
			$messageIds,
			static fn (int $messageId): bool => !isset($named[$messageId]),
		));
	}

	/**
	 * The record the import created for a delivered letter of the page, where the fingerprints
	 * name exactly one such record and the confirmation agrees with it.
	 *
	 * Everything up to the confirmation is answered for the whole page by the indexed tables, so
	 * a page of letters with no twin at all - which is what almost every page is - costs a
	 * handful of queries and not one per letter. The stored bodies are read only for the pairs
	 * that got that far.
	 *
	 * @param int[] $deliveredIds
	 * @return array<int, array{id: int, placements: string[], canonical: CanonicalMessageData}>
	 *         Delivered letter => the record of the import it turns out to be.
	 */
	private function findImportedTwins(Context $context, array $deliveredIds): array
	{
		if ($deliveredIds === [])
		{
			return [];
		}

		$candidatesOf = $this->lookupByFingerprints($context->mailboxId, $deliveredIds);
		if ($candidatesOf === [])
		{
			return [];
		}

		$reconsiderable = $this->reconsiderableRecords($context, $this->flatten($candidatesOf));
		if ($reconsiderable === [])
		{
			return [];
		}

		$twins = [];

		foreach ($candidatesOf as $deliveredMessageId => $candidates)
		{
			$possible = array_values(array_filter(
				$candidates,
				static fn (int $candidate): bool => $candidate !== $deliveredMessageId
					&& isset($reconsiderable[$candidate])
				,
			));

			/*
				More than one record is what an AMBIGUOUS decision is for, and this pass never makes
				one: a letter it cannot tell apart keeps the identity the import gave it.
			*/
			if (count($possible) !== 1)
			{
				continue;
			}

			$importedMessageId = (int)reset($possible);
			$canonical = $this->confirm($context->mailboxId, $importedMessageId, $deliveredMessageId);

			if ($canonical !== null)
			{
				$twins[$deliveredMessageId] = [
					'id' => $importedMessageId,
					'placements' => $reconsiderable[$importedMessageId],
					'canonical' => $canonical,
				];
			}
		}

		return $twins;
	}

	/**
	 * @param int[] $deliveredIds
	 * @return array<int, int[]> Delivered letter => the letters sharing a fingerprint with it.
	 */
	private function lookupByFingerprints(int $mailboxId, array $deliveredIds): array
	{
		$rows = MessageFingerprintTable::getList([
			'select' => ['MESSAGE_ID', 'KIND', 'HASH'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $deliveredIds,
				'=ALGORITHM_VERSION' => CanonicalMessageNormalizer::VERSION,
			],
		])->fetchAll();

		$owners = [];
		foreach ($rows as $row)
		{
			$owners[(string)$row['KIND']][(string)$row['HASH']][] = (int)$row['MESSAGE_ID'];
		}

		$candidatesOf = [];

		foreach ($owners as $kind => $byHash)
		{
			$found = $this->candidates->findByHashes($mailboxId, (string)$kind, array_keys($byHash));

			foreach ($byHash as $hash => $deliveredOfHash)
			{
				foreach ($deliveredOfHash as $deliveredMessageId)
				{
					foreach ($found[$hash] ?? [] as $candidate)
					{
						$candidatesOf[$deliveredMessageId][(int)$candidate] = (int)$candidate;
					}
				}
			}
		}

		return array_map(array_values(...), $candidatesOf);
	}

	/**
	 * @param array<int, int[]> $candidatesOf
	 * @return int[]
	 */
	private function flatten(array $candidatesOf): array
	{
		$ids = [];
		foreach ($candidatesOf as $candidates)
		{
			foreach ($candidates as $candidate)
			{
				$ids[$candidate] = $candidate;
			}
		}

		return array_values($ids);
	}

	/**
	 * The candidates whose identity this pass may overrule, with every placement of this
	 * generation serving each of them.
	 *
	 * A candidate qualifies when the import created it out of nothing - a NEW result that found
	 * no candidate at all - and nothing about it was ever refused as ambiguous. The placements
	 * come back whole and not only the ones of the reconsidered result: the same letter of the
	 * new source may be shown in several folders, and the further placements were matched to
	 * this very record. Moving one and leaving the others pointing at a record about to go is
	 * the one outcome this pass may not produce.
	 *
	 * @param int[] $messageIds
	 * @return array<int, string[]> Candidate => its placements of this generation.
	 */
	private function reconsiderableRecords(Context $context, array $messageIds): array
	{
		$rows = $this->journalRowsOf($context, $messageIds);
		$reconsiderable = [];

		foreach ($rows as $messageId => $results)
		{
			$created = false;
			$refused = false;
			$placements = [];

			foreach ($results as $result)
			{
				$placements[] = (string)$result['UID_ID'];

				if ((string)$result['STATE'] === SourceGenerationMatchTable::STATE_AMBIGUOUS)
				{
					$refused = true;
				}

				$created = $created || (
					(string)$result['STATE'] === SourceGenerationMatchTable::STATE_NEW
					&& (string)$result['REASON_CODE'] === MatchDecision::REASON_NO_CANDIDATE
				);
			}

			if ($created && !$refused)
			{
				$reconsiderable[$messageId] = $placements;
			}
		}

		return $reconsiderable;
	}

	/**
	 * What the import decided about the letters of one page.
	 *
	 * The mailbox leads the condition, and it narrows nothing: a context is refused unless the
	 * generation it names really belongs to the mailbox it names
	 * ({@see ContextResolver::resolveForMigrationImport()}), and every row of the journal is
	 * written with both taken from that one context. What it does instead is give the lookup the
	 * index it was made for - the journal is keyed by the mailbox and the letter. Asked by the
	 * generation alone, the same lookup is a range over every row the generation holds, that is
	 * one row per letter the import has walked, and a page of five hundred letters pays it whole.
	 *
	 * @param int[] $messageIds
	 * @return array<int, array<int, array{UID_ID: string, STATE: string, REASON_CODE: string}>>
	 */
	private function journalRowsOf(Context $context, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = SourceGenerationMatchTable::getList([
			'select' => ['UID_ID', 'MESSAGE_ID', 'STATE', 'REASON_CODE'],
			'filter' => [
				'=MAILBOX_ID' => $context->mailboxId,
				'@MESSAGE_ID' => $messageIds,
				'=GENERATION_ID' => $context->generationId,
			],
		])->fetchAll();

		$byMessage = [];
		foreach ($rows as $row)
		{
			$byMessage[(int)$row['MESSAGE_ID']][] = [
				'UID_ID' => (string)$row['UID_ID'],
				'STATE' => (string)$row['STATE'],
				'REASON_CODE' => (string)$row['REASON_CODE'],
			];
		}

		return $byMessage;
	}

	/**
	 * @return CanonicalMessageData|null The view of the imported record, null when the two are
	 *         not confirmed to be one letter.
	 */
	private function confirm(int $mailboxId, int $importedMessageId, int $deliveredMessageId): ?CanonicalMessageData
	{
		$canonical = $this->candidates->loadCanonical($mailboxId, [$importedMessageId, $deliveredMessageId]);

		$imported = $canonical[$importedMessageId] ?? null;
		$delivered = $canonical[$deliveredMessageId] ?? null;

		if ($imported === null || $delivered === null || !$this->matcher->agreesWith($imported, $delivered))
		{
			return null;
		}

		return $imported;
	}

	/**
	 * The placements of the prepared generation start serving the delivered letter, and the
	 * record the import created goes with the last of them.
	 *
	 * @param array{id: int, placements: string[], canonical: CanonicalMessageData} $twin
	 */
	private function reunite(Context $context, array $twin, int $deliveredMessageId): void
	{
		$moved = 0;

		foreach ($twin['placements'] as $uidId)
		{
			if ($this->importer->relink($context, $uidId, $deliveredMessageId, MatchDecision::REASON_LATE_DELIVERY))
			{
				++$moved;
			}
		}

		if ($moved === 0)
		{
			return;
		}

		/*
			The representation the new source gave the letter belongs to the letter it really
			describes: the next generation must find that letter by it, and the fingerprints of
			the record being dropped go with the record.
		*/
		$this->matcher->registerRepresentation($context, $deliveredMessageId, $twin['canonical']);

		$this->dropImportedLetter($context, $twin['id']);
	}

	/**
	 * The record the import created, once nothing serves it any more.
	 *
	 * A binding refuses the deletion outright. An import publishes no delivery effect and
	 * creates no binding, so a record that has one was touched by something this pass knows
	 * nothing about - and losing that is worse than keeping a record no screen can reach: with
	 * no placement left it is invisible either way.
	 */
	private function dropImportedLetter(Context $context, int $messageId): void
	{
		$placements = MailMessageUidTable::getCount([
			'=MAILBOX_ID' => $context->mailboxId,
			'=MESSAGE_ID' => $messageId,
		]);

		if ($placements > 0)
		{
			return;
		}

		$bindings = MessageAccessTable::getCount([
			'=MAILBOX_ID' => $context->mailboxId,
			'=MESSAGE_ID' => $messageId,
		]);

		if ($bindings > 0)
		{
			AddMessage2Log(
				sprintf(
					'Source generation late delivery: the imported message %u of the mailbox %u carries'
					. ' %u binding(s) and is left in place',
					$messageId,
					$context->mailboxId,
					$bindings,
				),
				'mail',
				2,
				false,
			);

			return;
		}

		\CMailMessage::delete($messageId, $context->mailboxId);

		// The record is gone for good, so the conversation projection may not keep it either
		(new ConversationBackfill())->removeMessages($context->mailboxId, [$messageId]);
	}
}
