<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\IdentifiedRecordsInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\PreselectedActivitiesInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyFactory;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\TextLengthConfig;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use CCrmOwnerType;

/**
 * What a client and its deals have been talked to about: transcripts of calls, comments, to-do items
 * and letters, read of every source by the strategies of repeat sales.
 *
 * The entity type of a source is an argument of a strategy, so the same four of them answer for the
 * client and for each of its deals. Calls and to-do items are picked by the owner of the activity and
 * cannot repeat between sources; letters and comments are picked by the bindings of one and arrive
 * once per source they are bound to, which is what the repeats are dropped for. A repeat is a row met
 * again and told apart by its identifier - two records of the same wording are two things said and
 * both are told about. Dropping the repeats happens before any limit is applied, otherwise the limit
 * is spent on the same row twice.
 *
 * The strategies are shared with the collector of the repeat sales, which asks for texts long enough
 * to reason about a history and drops anything shorter. A summary shows what there is instead, so the
 * strategies of this reader are built with lengths of their own and keep the shortest of comments.
 *
 * The two kinds picked by the owner are not looked for here at all: {@see LastCallSummaryReader} has
 * already read the activities of every source in one query and marked which of them belong to which
 * kind, so the strategies of those two are told the activities instead of being asked to find them.
 * That is what the single read is for - a strategy asked to find them sends a query of the owner, and
 * the storage has no index that carries the order of such a query, so every one of them sorts the whole
 * history of the card again. The other two kinds are left to find their own: comments live in another
 * table entirely, and letters are picked by the bindings of a source and not by its owner.
 *
 * The count of a kind is allowed the limit of every source it was read of, so a client with deals
 * tells about more of a kind than a client without them. Checking that count is a guard and not a
 * signal of the section: every one of the strategies cuts its query down to the limit it is asked for,
 * so a merged kind cannot outgrow it and the guard is out of reach as long as those four answer it -
 * the decision to keep the guard anyway, and what it costs, are recorded in ALG-01.
 *
 * What a source did leave behind is told by a probe instead, and only to the caller that asks for one:
 * the source is read one record past the limit of its kind, and that record is the whole of the signal -
 * it is dropped and never reaches the section. Counting what the section does carry would answer
 * differently, because a kind that had exactly the limit and a kind that had more of it look the same
 * from there. The probe is off by default, so a summary of a client is answered as it was before.
 *
 * Every kind is given a share of the section of its own: the limits of the sources add up past what
 * the section carries, and a common ceiling would be spent whole by whoever was read first. A kind
 * that has spent its share is not read of the remaining sources at all - what came back would only be
 * dropped, and it costs a query all the same.
 *
 * The section carries a list per kind, plus the summary of the latest call and its owner as fields of
 * their own when there is a summary to tell. The summary is bounded like everything else: it comes out
 * of a `longtext` column and is counted inside the ceiling of the section and not on top of it.
 *
 * @internal
 */
class CommunicationsReader
{
	private readonly StrategyFactory $strategyFactory;
	private readonly JobRepository $jobRepository;

	public function __construct(?StrategyFactory $strategyFactory = null, ?JobRepository $jobRepository = null)
	{
		$this->strategyFactory = $strategyFactory ?? new StrategyFactory(TextLengthConfig::withoutMinimumLength());
		$this->jobRepository = $jobRepository ?? JobRepository::getInstance();
	}

	/**
	 * @param ItemIdentifier[] $sources The client and the deals the communications are read of.
	 * @param SourceActivities $activities The activities of the sources, already read by
	 * {@see LastCallSummaryReader::readActivities()} and marked there with who asked for them, the
	 * freshest of a source first. Read, they are the whole answer for the kinds they cover - a source
	 * with none of a kind inside them has none of that kind, and no strategy is sent to look. Unread,
	 * every kind looks for its own records the way it always has. They also say whether the search of
	 * the latest call summary reached the end of the calls of the sources: it did not, and no summary
	 * was found, means an older call may carry one - and the section says so instead of passing for the
	 * whole of what the sources were ever told.
	 * @param int $perKind Records of one kind a single source is read of. Both limits of the section are
	 * arguments because both were measured over a client with six sources of its own: a summary built
	 * over one source needs numbers of its own, and the defaults keep the client answered as before.
	 * @param int $kindLength Characters of the section one kind is allowed.
	 * @param bool $isProbingForMore Whether every source is read one record past the limit of a kind, so
	 * that a kind read to the limit is told apart from a kind that had more to tell. The probe record is
	 * the signal and nothing else - it never reaches the section. Off by default, because the guard of
	 * the merged count is what a summary of a client has always been answered by.
	 */
	public function read(
		array $sources,
		SourceActivities $activities,
		?LastCallSummary $lastCall = null,
		int $perKind = SummaryLimits::COMMUNICATIONS_PER_KIND,
		int $kindLength = SummaryLimits::KIND_LENGTH,
		bool $isProbingForMore = false,
	): SummarySection
	{
		[$data, $isItemLimitHit, $isLengthLimitHit] = $this->collect(
			$sources,
			$activities,
			$perKind,
			$kindLength,
			$isProbingForMore,
		);

		$isReadDepthHit = false;
		if ($lastCall !== null)
		{
			[$text, $isSummaryCut] = self::within($lastCall->text, SummaryLimits::LAST_CALL_SUMMARY_LENGTH);
			$isLengthLimitHit = $isLengthLimitHit || $isSummaryCut;

			$data['latestCallSummary'] = $text;
			$data['latestCallOwner'] = [
				'entityType' => self::sourceName($lastCall->owner),
				'entityId' => $lastCall->owner->getEntityId(),
			];
		}
		else
		{
			// a summary that was found is the latest one; one that was not may be below the window
			$isReadDepthHit = $activities->areOlderCallsUnsearched;
		}

		if ($data === [])
		{
			return SummarySection::empty();
		}

		return SummarySection::filled(
			$data,
			self::truncationReason($isItemLimitHit, $isReadDepthHit, $isLengthLimitHit),
		);
	}

	/**
	 * The communications of every source, gathered kind by kind and already inside every limit the
	 * section has: a row told of before is dropped, a text longer than its kind allows is cut, and a kind
	 * that has spent its share of the section is left alone for the rest of the sources.
	 *
	 * Dropping the repeats happens before any limit is applied, otherwise the limit is spent on the same
	 * row twice. A row is recognised by its identifier and never by its text: a kind whose strategy does
	 * not tell which row a record came from is read of the owner of the activity and cannot repeat
	 * between the sources at all. A record that does not fit what is left of a share is dropped whole
	 * rather than squeezed in, so what the section does carry stays whole.
	 *
	 * The probe record, when it was asked for, is dropped before anything else happens to what came back:
	 * it says that the source had more of the kind and takes no place in the section for saying it.
	 *
	 * @param ItemIdentifier[] $sources
	 * @return array{0: array<string, array<int, array{source: string, text: string}>>, 1: bool, 2: bool}
	 * The records keyed by the kind - the client first and each deal after it, the freshest communication
	 * of a source first - and whether a record and a text had to be left out.
	 */
	private function collect(
		array $sources,
		SourceActivities $activities,
		int $perKind,
		int $kindLength,
		bool $isProbingForMore,
	): array
	{
		$this->warmTranscriptsOf($activities->items);
		$preselected = $activities->areRead ? self::activityIdsByKind($activities->items) : null;

		$probe = $isProbingForMore ? SummaryLimits::PROBE_RECORD : 0;
		$itemLimit = $perKind * count($sources);
		$records = [];
		$seen = [];
		$spent = [];
		$isItemLimitHit = false;
		$isLengthLimitHit = false;

		foreach ($sources as $source)
		{
			$sourceName = self::sourceName($source);
			foreach ($this->strategiesFor($source->getEntityTypeId()) as $kind => $strategy)
			{
				if (($spent[$kind] ?? 0) >= $kindLength)
				{
					$isLengthLimitHit = true;

					continue;
				}

				$found = self::recordsOf($strategy, $source, $kind, $perKind + $probe, $preselected);
				if ($probe > 0 && count($found) > $perKind)
				{
					$isItemLimitHit = true;
					$found = array_slice($found, 0, $perKind);
				}

				foreach ($found as ['id' => $recordId, 'text' => $text])
				{
					if ($recordId !== null)
					{
						if (isset($seen[$kind][$recordId]))
						{
							continue;
						}

						$seen[$kind][$recordId] = true;
					}

					if (count($records[$kind] ?? []) >= $itemLimit)
					{
						$isItemLimitHit = true;

						continue;
					}

					[$text, $isTextCut] = self::within($text, self::lengthLimitOf($kind));
					$isLengthLimitHit = $isLengthLimitHit || $isTextCut;

					if (($spent[$kind] ?? 0) + mb_strlen($text) > $kindLength)
					{
						$spent[$kind] = $kindLength;
						$isLengthLimitHit = true;

						break;
					}

					$records[$kind][] = ['source' => $sourceName, 'text' => $text];
					$spent[$kind] = ($spent[$kind] ?? 0) + mb_strlen($text);
				}
			}
		}

		return [$records, $isItemLimitHit, $isLengthLimitHit];
	}

	/**
	 * The records of one kind of one source, taken of the activities that were read for this reader when
	 * there were any and looked for by the strategy itself otherwise.
	 *
	 * A kind that was read for is answered entirely out of what was read, the empty answer included: the
	 * reader of the activities covers the kinds it marks, so a source with none of them marked has none
	 * of that kind and sending the strategy to confirm it costs a query for an empty answer.
	 *
	 * A record carries the row it was made of whenever the strategy of it tells which one that was. The
	 * two kinds read of the owner of an activity do not: a record of theirs belongs to one source and
	 * meeting it again is not something that happens.
	 *
	 * @param array<string, array<string, int[]>>|null $preselected
	 * @return array<int, array{id: int|null, text: string}>
	 */
	private static function recordsOf(
		StrategyInterface $strategy,
		ItemIdentifier $source,
		string $kind,
		int $limit,
		?array $preselected,
	): array
	{
		if ($preselected !== null && $strategy instanceof PreselectedActivitiesInterface)
		{
			return self::unidentified($strategy->collectByActivityIds(
				array_slice($preselected[self::sourceKey($source)][$kind] ?? [], 0, $limit),
			));
		}

		if ($strategy instanceof IdentifiedRecordsInterface)
		{
			return $strategy->collectIdentified($source->getEntityId(), $limit);
		}

		return self::unidentified($strategy->collect($source->getEntityId(), $limit));
	}

	/**
	 * @param array<int, string> $texts
	 * @return array<int, array{id: null, text: string}>
	 */
	private static function unidentified(array $texts): array
	{
		return array_map(
			static fn ($text): array => ['id' => null, 'text' => (string)$text],
			array_values($texts),
		);
	}

	/**
	 * Which activities belong to which kind of which source, kept in the order they were read in - the
	 * freshest of a source first, which is the order a kind takes its records in.
	 *
	 * @param array<int, array> $activities
	 * @return array<string, array<string, int[]>>
	 */
	private static function activityIdsByKind(array $activities): array
	{
		$activityIds = [];
		foreach ($activities as $activity)
		{
			$sourceKey = (int)$activity['OWNER_TYPE_ID'] . ':' . (int)$activity['OWNER_ID'];
			foreach (self::marksOfThePreselectedKinds() as $kind => $mark)
			{
				if (!empty($activity[$mark]))
				{
					$activityIds[$sourceKey][$kind][] = (int)$activity['ID'];
				}
			}
		}

		return $activityIds;
	}

	/**
	 * The kinds the activities are read for, and the mark {@see LastCallSummaryReader} leaves on the
	 * rows of each. A kind absent from here looks for its own records whatever was read.
	 *
	 * @return array<string, string>
	 */
	private static function marksOfThePreselectedKinds(): array
	{
		return [
			ActivityType::CALL_RECORDING_TRANSCRIPTS->value => LastCallSummaryReader::IS_TRANSCRIPT_ASKED,
			ActivityType::TODOS->value => LastCallSummaryReader::IS_TODO_ASKED,
		];
	}

	private static function sourceKey(ItemIdentifier $source): string
	{
		return $source->getEntityTypeId() . ':' . $source->getEntityId();
	}

	/**
	 * The transcripts the strategy of the calls is about to ask about, warmed in one query before it is
	 * asked anything: the strategy takes the transcript of every call it is given out of the repository
	 * of the AI jobs one by one, and a warm cache of the repository answers those without a query of its
	 * own.
	 *
	 * Which calls those are is not guessed here: the reader of the activities marks the ones the strategy
	 * is going to be given, and it is the same mark the strategy is given them by. A call the strategy
	 * will not ask about is not warmed - the transcript of it would be read for nothing.
	 *
	 * @param array<int, array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int}> $activities
	 */
	private function warmTranscriptsOf(array $activities): void
	{
		$activityIds = [];
		foreach ($activities as $activity)
		{
			if (!empty($activity[LastCallSummaryReader::IS_TRANSCRIPT_ASKED]))
			{
				$activityIds[] = (int)$activity['ID'];
			}
		}

		if ($activityIds !== [])
		{
			$this->jobRepository->warmTranscribeCacheForActivities($activityIds);
		}
	}

	/**
	 * Open lines are left out, and left out by never asking for the strategy of them: their content
	 * belongs to `imopenlines` and the access model of that module is not checked here. A key dropped
	 * after the fact would not help - the foreign module would have been read by then.
	 *
	 * @return array<string, StrategyInterface> Keyed by the kind of the strategy.
	 */
	private function strategiesFor(int $entityTypeId): array
	{
		return [
			ActivityType::CALL_RECORDING_TRANSCRIPTS->value
				=> $this->strategyFactory->createCallRecordingStrategy($entityTypeId),
			ActivityType::COMMENTS->value => $this->strategyFactory->createCommentsStrategy($entityTypeId),
			ActivityType::TODOS->value => $this->strategyFactory->createTodosStrategy($entityTypeId),
			ActivityType::EMAILS->value => $this->strategyFactory->createEmailsStrategy($entityTypeId),
		];
	}

	/**
	 * @return array{0: string, 1: bool} The text and whether it had to be cut.
	 */
	private static function within(string $text, int $limit): array
	{
		return mb_strlen($text) > $limit ? [mb_substr($text, 0, $limit), true] : [$text, false];
	}

	private static function lengthLimitOf(string $kind): int
	{
		return $kind === ActivityType::CALL_RECORDING_TRANSCRIPTS->value
			? SummaryLimits::CALL_TEXT_LENGTH
			: SummaryLimits::TEXT_LENGTH
		;
	}

	/**
	 * A record left out entirely is the coarser loss, so it names the reason when both happened. A read
	 * that stopped above the end of the calls comes next: it costs the section one field and not a
	 * record of every kind, and it still says more than a text cut at its limit.
	 */
	private static function truncationReason(
		bool $isItemLimitHit,
		bool $isReadDepthHit,
		bool $isLengthLimitHit,
	): ?TruncationReason
	{
		return match (true)
		{
			$isItemLimitHit => TruncationReason::ItemLimit,
			$isReadDepthHit => TruncationReason::ReadDepth,
			$isLengthLimitHit => TruncationReason::LengthLimit,
			default => null,
		};
	}

	private static function sourceName(ItemIdentifier $source): string
	{
		return mb_strtolower(CCrmOwnerType::ResolveName($source->getEntityTypeId()));
	}
}
