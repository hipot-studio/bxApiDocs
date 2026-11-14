<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

/**
 * The freshest call an AI summary was made of, looked for among the activities of the client itself as
 * well as among the ones of its deals: a client without a single deal still gets called, and those
 * calls are what the risks of the summary are told from.
 *
 * The activities themselves are read here for everyone who needs them, the reader of the communications
 * included, and a source is read once for all of them. They are not all after the same rows - a summary
 * is looked for among the calls of the telephony, the transcripts are told of the calls that carry a
 * description, and the to-do items are a kind of the section of its own - so a row comes back marked
 * with who asked for it and a row several of them asked for carries several marks.
 *
 * One read and not one per asker, because the condition of every one of them is the owner of the
 * activity and the storage has no index that carries the order underneath it: a query by the owner
 * reaches the rows of the card by `IX_B_CRM_ACT_20` and then sorts every one of them to answer ten, so
 * four such queries sort the history of the owner four times over. The read takes the newest activities
 * of the kinds all of them need and the kinds are told apart in PHP, where the columns are narrow and
 * the sorting is already paid for.
 *
 * The handful the read takes is a reserve over what the askers want, and one asker may crowd out
 * another inside it - a thousand calls of a busy company ahead of its to-do items. That is answered and
 * not accepted: a kind that came back short while the handful was full is read again by a query of its
 * own, which is the exact answer for that kind. The reserve holds for every card a portal normally has,
 * so the exact read is the rare case and not the usual one.
 *
 * Whether a call has a summary is answered by the results of the AI jobs and by nothing else - the
 * card of the call is not read apart by hand. Which of the calls have a job at all is asked in one
 * query, so a client whose calls nobody summarized pays a single read for the answer.
 *
 * @internal
 */
class LastCallSummaryReader
{
	/** Calls of a source, and of all of them together, the results of the AI jobs are asked about. */
	private const CANDIDATE_CALLS = 10;

	/**
	 * Calls of a source a read asks for: the ones the search looks through plus the probe record that
	 * says the card has calls below them ({@see SummaryLimits::PROBE_RECORD}).
	 *
	 * The probe is never looked through and never widens the search. It is read because the search is
	 * bounded and a bounded search that found nothing has to say which of the two it means: the client
	 * has no summarized call at all, or it has none among the calls that were looked at. Taken for the
	 * first, a summary tells a card busy with calls as a card nobody ever summarized a call of, and the
	 * section is not even marked as incomplete.
	 */
	private const CANDIDATE_CALLS_WITH_PROBE = self::CANDIDATE_CALLS + SummaryLimits::PROBE_RECORD;

	/**
	 * Newest activities of a source the one read takes, out of which every kind picks its own.
	 *
	 * It is a reserve over what the kinds want together - ten calls of a summary plus a handful of each
	 * of the other two - and not a ceiling of its own: the kinds share the read, so a card busy with one
	 * of them pushes the others out of the window and leaves them to a read of their own. Two hundred
	 * holds every card a portal normally has; a card where the newest two hundred calls and to-do items
	 * carry neither a transcript nor a to-do item pays one exact read for the kind it crowded out, which
	 * is what that kind used to cost anyway.
	 *
	 * Making it deeper is nearly free of the storage and not free of the connection: the rows of the card
	 * are reached and sorted whatever the ceiling is, while every row of the window travels back. Two
	 * hundred narrow rows per source is the balance taken.
	 */
	private const SOURCE_ACTIVITIES = 200;

	/** Marks a call the search of the latest summary looks through. */
	public const IS_SUMMARY_CANDIDATE = 'IS_SUMMARY_CANDIDATE';

	/** Marks a call the strategy of the transcripts is going to be asked about. */
	public const IS_TRANSCRIPT_ASKED = 'IS_TRANSCRIPT_ASKED';

	/** Marks a to-do item the strategy of the to-do items is going to be asked about. */
	public const IS_TODO_ASKED = 'IS_TODO_ASKED';

	/** Whether the row carries a text of its own, answered by the read instead of travelling with it. */
	private const HAS_DESCRIPTION = 'HAS_DESCRIPTION';

	/**
	 * A source at a time: one owner takes the index of the owner, while a single query of every owner at
	 * once takes the index of the provider and pays the telephony of the whole portal for it.
	 *
	 * Every source is asked for as many calls as the search looks through in total, so the freshest of
	 * the merged list are the freshest calls of the client whatever source they belong to.
	 *
	 * @param ItemIdentifier[] $sources The client and the deals the activities are read of.
	 * @param int $perKind Records of a single source one kind of the section is allowed - the limit
	 * {@see CommunicationsReader} was asked with plus the probe record when the caller asked for one. The
	 * read has to cover exactly those records, or every record above them costs a read of its own.
	 * @return SourceActivities The activities of one source follow each other, the freshest of the
	 * source first, every one of them once and marked with {@see self::IS_SUMMARY_CANDIDATE},
	 * {@see self::IS_TRANSCRIPT_ASKED}, {@see self::IS_TODO_ASKED} or several of those at once. Shaped
	 * `array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, CREATED: DateTime|null}` and the marks.
	 */
	public function readActivities(
		array $sources,
		int $perKind = SummaryLimits::COMMUNICATIONS_PER_KIND,
	): SourceActivities
	{
		$activities = [];
		foreach ($sources as $source)
		{
			foreach ($this->activitiesOf($source, $perKind) as $activity)
			{
				$activityId = (int)$activity['ID'];
				$activities[$activityId] = $activity + ($activities[$activityId] ?? []);
			}
		}

		$items = array_values($activities);

		return SourceActivities::read($items, self::hasCallsBelowTheSearchWindow($items));
	}

	/**
	 * Whether the sources have calls {@see self::read()} is not going to look through - the ones below
	 * the window of the search, read as the probe record and counted here.
	 *
	 * Counted over the merged list and not per source, because the search is merged too: it looks
	 * through the freshest calls of the client and of its deals together, so a source may lose its
	 * calls to the window of another one as well as to its own.
	 *
	 * @param array<int, array> $calls
	 */
	private static function hasCallsBelowTheSearchWindow(array $calls): bool
	{
		return count(self::summaryCandidatesOf($calls)) > self::CANDIDATE_CALLS;
	}

	/**
	 * The activities of one source, split into the kinds that asked for them.
	 *
	 * The handful is read the freshest first, so the first records of a kind inside it are the freshest
	 * records of that kind - unless the handful ran full, in which case a kind that came back short may
	 * have been crowded out by another and is read again by itself. A kind that filled its limit was not
	 * crowded out by anybody: a record of it fresher than the ones that were taken would have been inside
	 * the handful too.
	 *
	 * @return array<int, array> Marked and ordered the freshest first, which is the order whoever reads
	 * a kind out of them takes its records in.
	 */
	private function activitiesOf(ItemIdentifier $source, int $perKind): array
	{
		$depth = max(self::SOURCE_ACTIVITIES, self::CANDIDATE_CALLS_WITH_PROBE + 2 * $perKind);
		$rows = $this->readSourceActivities($source, $depth);
		$isCrowdingPossible = count($rows) >= $depth;

		$candidates = self::firstOfKind($rows, self::isCandidate(...), self::CANDIDATE_CALLS_WITH_PROBE);
		if ($isCrowdingPossible && count($candidates) < self::CANDIDATE_CALLS_WITH_PROBE)
		{
			$candidates = $this->readCallsOf($source);
		}

		$transcriptCalls = self::firstOfKind($rows, self::isTranscriptCall(...), $perKind);
		if ($isCrowdingPossible && count($transcriptCalls) < $perKind)
		{
			$transcriptCalls = $this->readTranscriptCallsOf($source, $perKind);
		}

		$todos = self::firstOfKind($rows, self::isTodo(...), $perKind);
		if ($isCrowdingPossible && count($todos) < $perKind)
		{
			$todos = $this->readTodosOf($source, $perKind);
		}

		$activities = [];
		foreach (
			[
				self::IS_SUMMARY_CANDIDATE => $candidates,
				self::IS_TRANSCRIPT_ASKED => $transcriptCalls,
				self::IS_TODO_ASKED => $todos,
			]
			as $mark => $kind
		)
		{
			foreach ($kind as $row)
			{
				$activityId = (int)$row['ID'];
				$activities[$activityId] = [$mark => true]
					+ ($activities[$activityId] ?? self::recordOf($row))
				;
			}
		}

		return self::freshestFirst(array_values($activities));
	}

	/**
	 * The newest activities of the source every kind of the section is told from, in one read.
	 *
	 * The text of a record is not among the columns and the origin of the telephony is: the first is a
	 * `longtext` the kinds that need it fetch by the primary key of the few rows they keep, the second is
	 * what tells a call of the telephony from a call typed in by hand and costs a column. Whether a
	 * record carries a text at all is answered as a flag for the same reason.
	 *
	 * The rows without either are left out by the condition rather than by the splitting: a card is full
	 * of calls nobody said anything about, and they would spend the handful on being dropped.
	 *
	 * @return array<int, array>
	 */
	protected function readSourceActivities(ItemIdentifier $source, int $limit): array
	{
		return self::sourceActivitiesQuery($source, $limit)->fetchAll();
	}

	/**
	 * Built apart from being sent, so that the shape of the one read is read by a case of its own: it is
	 * the query the whole of the summary is told from, and the columns it does and does not carry are
	 * what the kinds are split by afterwards.
	 */
	private static function sourceActivitiesQuery(ItemIdentifier $source, int $limit): Query
	{
		return ActivityTable::query()
			->registerRuntimeField(
				new ExpressionField(
					self::HAS_DESCRIPTION,
					'CASE WHEN %s IS NULL THEN 0 ELSE 1 END',
					'DESCRIPTION',
					['data_type' => 'integer'],
				),
			)
			->setSelect([
				'ID',
				'OWNER_TYPE_ID',
				'OWNER_ID',
				'CREATED',
				'PROVIDER_ID',
				'ORIGIN_ID',
				self::HAS_DESCRIPTION,
			])
			->where('OWNER_TYPE_ID', $source->getEntityTypeId())
			->where('OWNER_ID', $source->getEntityId())
			->whereIn('PROVIDER_ID', [Call::ACTIVITY_PROVIDER_ID, ToDo::PROVIDER_ID])
			->where(
				Query::filter()
					->logic('or')
					->whereNotNull('ORIGIN_ID')
					->whereNotNull('DESCRIPTION')
				,
			)
			->setOrder(['CREATED' => 'DESC'])
			->setLimit($limit)
		;
	}

	/**
	 * The rights of the user have been applied to the sources by the caller, so the query asks for
	 * none of its own. A call is recognized the way the summary of a deal recognizes it: the provider
	 * of the activity plus an identifier of the call behind it.
	 *
	 * Asked only when the one read of the source could not answer this kind for certain.
	 *
	 * @return array<int, array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, CREATED: DateTime|null}>
	 */
	protected function readCallsOf(ItemIdentifier $source): array
	{
		return self::callsQuery($source)->fetchAll();
	}

	private static function callsQuery(ItemIdentifier $source): Query
	{
		return self::ofSource($source)
			->where('PROVIDER_ID', Call::ACTIVITY_PROVIDER_ID)
			->whereNotNull('ORIGIN_ID')
			->setLimit(self::CANDIDATE_CALLS_WITH_PROBE)
		;
	}

	/**
	 * The calls {@see \Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\CallRecordingStrategy}
	 * is going to be asked the transcripts of, asked for the way the strategy asks for them: the same
	 * provider, a description of its own, the same order and the same count
	 * {@see CommunicationsReader} allows a kind of a source.
	 *
	 * Asked only when the one read of the source could not answer this kind for certain: the calls of a
	 * summary and the calls of a transcript do not agree - a call of the telephony has an origin and may
	 * carry no description, a call written by hand carries a description and has no origin - so one of
	 * them can fill the handful of the read and leave the other short.
	 *
	 * @return array<int, array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, CREATED: DateTime|null}>
	 */
	protected function readTranscriptCallsOf(ItemIdentifier $source, int $perKind): array
	{
		return self::transcriptCallsQuery($source, $perKind)->fetchAll();
	}

	private static function transcriptCallsQuery(ItemIdentifier $source, int $perKind): Query
	{
		return self::ofSource($source)
			->where('PROVIDER_ID', Call::ACTIVITY_PROVIDER_ID)
			->whereNotNull('DESCRIPTION')
			->setLimit($perKind)
		;
	}

	/**
	 * The same for the to-do items of
	 * {@see \Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\TodosStrategy}, and asked under
	 * the same condition.
	 *
	 * @return array<int, array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, CREATED: DateTime|null}>
	 */
	protected function readTodosOf(ItemIdentifier $source, int $perKind): array
	{
		return self::todosQuery($source, $perKind)->fetchAll();
	}

	private static function todosQuery(ItemIdentifier $source, int $perKind): Query
	{
		return self::ofSource($source)
			->where('PROVIDER_ID', ToDo::PROVIDER_ID)
			->whereNotNull('DESCRIPTION')
			->setLimit($perKind)
		;
	}

	private static function ofSource(ItemIdentifier $source): Query
	{
		return ActivityTable::query()
			->setSelect(['ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'CREATED'])
			->where('OWNER_TYPE_ID', $source->getEntityTypeId())
			->where('OWNER_ID', $source->getEntityId())
			->setOrder(['CREATED' => 'DESC'])
		;
	}

	/**
	 * @param array<int, array> $rows
	 * @param callable(array): bool $isOfKind
	 * @return array<int, array>
	 */
	private static function firstOfKind(array $rows, callable $isOfKind, int $limit): array
	{
		return array_slice(array_values(array_filter($rows, $isOfKind)), 0, $limit);
	}

	private static function isCandidate(array $row): bool
	{
		return ($row['PROVIDER_ID'] ?? null) === Call::ACTIVITY_PROVIDER_ID
			&& ($row['ORIGIN_ID'] ?? null) !== null
		;
	}

	private static function isTranscriptCall(array $row): bool
	{
		return ($row['PROVIDER_ID'] ?? null) === Call::ACTIVITY_PROVIDER_ID && self::hasDescription($row);
	}

	private static function isTodo(array $row): bool
	{
		return ($row['PROVIDER_ID'] ?? null) === ToDo::PROVIDER_ID && self::hasDescription($row);
	}

	/**
	 * A row of the exact read carries no flag of its own: it was asked for by a condition that already
	 * demanded the text.
	 */
	private static function hasDescription(array $row): bool
	{
		return (bool)($row[self::HAS_DESCRIPTION] ?? true);
	}

	/**
	 * What travels on to whoever asked: the columns the two readers of the summary name, and none of
	 * the ones the splitting of the kinds needed.
	 *
	 * @return array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, CREATED: DateTime|null}
	 */
	private static function recordOf(array $row): array
	{
		return [
			'ID' => (int)$row['ID'],
			'OWNER_TYPE_ID' => (int)$row['OWNER_TYPE_ID'],
			'OWNER_ID' => (int)$row['OWNER_ID'],
			'CREATED' => $row['CREATED'] ?? null,
		];
	}

	/**
	 * @param array<int, array{ID: int, OWNER_TYPE_ID: int, OWNER_ID: int, CREATED: DateTime|null}> $calls
	 * @return LastCallSummary|null Null when none of the calls the search looks through has a summary,
	 * which is an answer and not a failure. Whether an older call of the sources could still carry one
	 * is said by {@see SourceActivities::$areOlderCallsUnsearched} and not by this answer: the search is
	 * bounded by {@see self::CANDIDATE_CALLS}, and the section that tells the summary admits it there.
	 */
	public function read(array $calls): ?LastCallSummary
	{
		$candidates = array_slice(
			self::freshestFirst(self::summaryCandidatesOf($calls)),
			0,
			self::CANDIDATE_CALLS,
		);
		if ($candidates === [])
		{
			return null;
		}

		$summarized = $this->readSummarizedCalls(
			array_map(static fn (array $candidate): int => (int)$candidate['ID'], $candidates),
		);

		foreach ($candidates as $candidate)
		{
			$activityId = (int)$candidate['ID'];
			if (!isset($summarized[$activityId]))
			{
				continue;
			}

			$jobResult = $this->readJobResult($activityId);
			$text = self::summaryTextOf($jobResult);
			if ($text === '')
			{
				continue;
			}

			return new LastCallSummary(
				$text,
				new ItemIdentifier((int)$candidate['OWNER_TYPE_ID'], (int)$candidate['OWNER_ID']),
				$jobResult,
			);
		}

		return null;
	}

	/**
	 * Which of the calls an AI job of a summary was ever made for. The result of a job is what the
	 * summary is taken from, and it is read of the repository call by call; this asks the storage of
	 * the jobs which of the calls are worth asking about at all.
	 *
	 * @param int[] $activityIds
	 * @return array<int, true> Keyed by the call.
	 */
	protected function readSummarizedCalls(array $activityIds): array
	{
		$rows = QueueTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
			->whereIn('ENTITY_ID', $activityIds)
			->where('TYPE_ID', SummarizeCallTranscription::TYPE_ID)
			->fetchAll()
		;

		$summarized = [];
		foreach ($rows as $row)
		{
			$summarized[(int)$row['ENTITY_ID']] = true;
		}

		return $summarized;
	}

	protected function readJobResult(int $activityId): ?Result
	{
		return JobRepository::getInstance()->getSummarizeCallTranscriptionResultByActivity($activityId);
	}

	/**
	 * A call the strategy of the transcripts asked for is no candidate of a summary: it may have no
	 * origin of the telephony behind it, and which calls the search looks through is not widened by
	 * whose cache is warmed alongside.
	 *
	 * @param array<int, array> $calls
	 * @return array<int, array>
	 */
	private static function summaryCandidatesOf(array $calls): array
	{
		return array_values(array_filter(
			$calls,
			static fn (array $call): bool => (bool)($call[self::IS_SUMMARY_CANDIDATE] ?? false),
		));
	}

	/**
	 * The order of the storage is not relied upon: which call is the latest one decides which client
	 * the summary is about, and which records of a kind are kept follows the same order, so it is
	 * decided here. The identifier settles a tie, so two rows created in the same second are ordered the
	 * same way twice rather than by whatever the storage answered.
	 *
	 * @param array<int, array> $candidates
	 * @return array<int, array>
	 */
	private static function freshestFirst(array $candidates): array
	{
		usort(
			$candidates,
			static fn (array $left, array $right): int => [self::createdAt($right), (int)$right['ID']]
				<=> [self::createdAt($left), (int)$left['ID']]
			,
		);

		return $candidates;
	}

	private static function createdAt(array $candidate): int
	{
		$created = $candidate['CREATED'] ?? null;

		return $created instanceof DateTime ? $created->getTimestamp() : 0;
	}

	private static function summaryTextOf(?Result $jobResult): string
	{
		$payload = $jobResult?->getPayload();

		return $payload instanceof SummarizeCallTranscriptionPayload ? trim($payload->summary) : '';
	}
}
