<?php

namespace Bitrix\HumanResources\Repository\HcmLink;

use Bitrix\HumanResources\Model\HcmLink\JobTable;
use Bitrix\HumanResources\Service\HcmLink\JobKillerService;
use Bitrix\HumanResources\Type\HcmLink\JobStatus;
use Bitrix\HumanResources\Type\HcmLink\JobType;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;

/**
 * Read-only aggregates of the exchange jobs for the monitoring page. It never writes: the page only
 * tells the administrator whether 1C is answering.
 *
 * It lives apart from JobRepository because the getter of that one is typed with an interface from
 * lib/Contract/, where the module forbids new code.
 */
class JobMonitoringRepository
{
	public const STATE_WAITING = 'waiting';
	public const STATE_NOT_PICKED_UP = 'notPickedUp';
	public const STATE_IN_PROGRESS = 'inProgress';
	public const STATE_UNFINISHED = 'unfinished';
	public const STATE_CANCELED = 'canceled';
	public const STATE_DONE = 'done';

	/**
	 * A PIN request and a payslip request carry a one-time code, are never resent and are canceled
	 * once they expire. Counting them would report a broken exchange over a perfectly alive channel.
	 */
	private const EXCLUDED_TYPES = [JobType::PIN_REQUEST, JobType::SALARY_VACATION_REQUEST];

	/**
	 * One pass over the jobs of the summary window. The state of a job is told by STATUS and
	 * CREATED_AT and never by UPDATED_AT, which has two writers: a resend of the agent and any
	 * intermediate answer of 1C.
	 *
	 * The moment of the last successful answer is taken by the same pass. Asking for it separately
	 * cost more than the whole aggregate: the window over CREATED_AT does not narrow a plan led by
	 * the index on STATUS, so such a query read the entire history of the finished jobs.
	 *
	 * The moments of the last trouble are taken by the same pass: without them an answer of any age
	 * would pass for proof that a cancel of the agent is over. Two of them, because they are dated
	 * differently and only the caller knows how long the agent waits before it gives up.
	 *
	 * @return array{
	 *     counters: list<array{companyId: int, state: string, isRecent: bool, isAgentCancel: bool, count: int}>,
	 *     lastAnswerAt: array<int, DateTime>,
	 *     lastSuccessStartedAt: array<int, DateTime>,
	 *     lastCancelAttemptAt: array<int, DateTime>,
	 *     lastStuckAt: array<int, DateTime>,
	 * }
	 */
	public function getCompanyAggregate(
		DateTime $createdAfter,
		DateTime $verdictBoundary,
		DateTime $stuckBefore,
	): array
	{
		$rows = JobTable::query()
			->registerRuntimeField($this->createStateField($verdictBoundary, $stuckBefore))
			->registerRuntimeField($this->createRecencyField($verdictBoundary))
			->registerRuntimeField($this->createAgentCancelField())
			->registerRuntimeField($this->createLastAnswerField())
			->registerRuntimeField($this->createLastSuccessStartField())
			->registerRuntimeField($this->createLastCancelAttemptField())
			->registerRuntimeField($this->createLastStuckField($stuckBefore))
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->setSelect([
				'COMPANY_ID', 'STATE', 'IS_RECENT', 'IS_AGENT_CANCEL',
				'LAST_ANSWER_AT', 'LAST_SUCCESS_STARTED_AT', 'LAST_CANCEL_ATTEMPT_AT', 'LAST_STUCK_AT', 'CNT',
			])
			->where('CREATED_AT', '>=', $createdAfter)
			->whereNotIn('TYPE', $this->getExcludedTypeValues())
			->setGroup(['COMPANY_ID', 'STATE', 'IS_RECENT', 'IS_AGENT_CANCEL'])
			->fetchAll()
		;

		$counters = [];
		$lastAnswerAt = [];
		$lastSuccessStartedAt = [];
		$lastCancelAttemptAt = [];
		$lastStuckAt = [];

		foreach ($rows as $row)
		{
			$companyId = (int)$row['COMPANY_ID'];
			$this->keepLatestMoment($lastAnswerAt, $companyId, $row['LAST_ANSWER_AT']);
			$this->keepLatestMoment($lastSuccessStartedAt, $companyId, $row['LAST_SUCCESS_STARTED_AT']);
			$this->keepLatestMoment($lastCancelAttemptAt, $companyId, $row['LAST_CANCEL_ATTEMPT_AT']);
			$this->keepLatestMoment($lastStuckAt, $companyId, $row['LAST_STUCK_AT']);

			$state = (string)$row['STATE'];
			if ($state === '')
			{
				continue;
			}

			$counters[] = [
				'companyId' => $companyId,
				'state' => $state,
				'isRecent' => (bool)$row['IS_RECENT'],
				'isAgentCancel' => (bool)$row['IS_AGENT_CANCEL'],
				'count' => (int)$row['CNT'],
			];
		}

		return [
			'counters' => $counters,
			'lastAnswerAt' => $lastAnswerAt,
			'lastSuccessStartedAt' => $lastSuccessStartedAt,
			'lastCancelAttemptAt' => $lastCancelAttemptAt,
			'lastStuckAt' => $lastStuckAt,
		];
	}

	/**
	 * The jobs of one company are split over several rows by state, so its latest moment of a kind is
	 * the latest over those rows.
	 *
	 * @param array<int, DateTime> $moments
	 */
	private function keepLatestMoment(array &$moments, int $companyId, mixed $moment): void
	{
		if (!($moment instanceof DateTime))
		{
			return;
		}

		$known = $moments[$companyId] ?? null;
		if ($known === null || $moment->getTimestamp() > $known->getTimestamp())
		{
			$moments[$companyId] = $moment;
		}
	}

	private function createStateField(DateTime $verdictBoundary, DateTime $stuckBefore): ExpressionField
	{
		$stuck = $this->toSqlDateTime($stuckBefore);
		$fresh = $this->toSqlDateTime($verdictBoundary);

		$started = JobStatus::STARTED->value;
		$inProgress = JobStatus::IN_PROGRESS->value;
		$canceled = JobStatus::CANCELED->value;
		$done = JobStatus::DONE->value;

		$waitingState = self::STATE_WAITING;
		$notPickedUpState = self::STATE_NOT_PICKED_UP;
		$inProgressState = self::STATE_IN_PROGRESS;
		$unfinishedState = self::STATE_UNFINISHED;
		$canceledState = self::STATE_CANCELED;
		$doneState = self::STATE_DONE;

		$expression = "CASE %s"
			. " WHEN {$started} THEN"
			. " CASE WHEN %s >= {$stuck} THEN '{$waitingState}' ELSE '{$notPickedUpState}' END"
			. " WHEN {$inProgress} THEN"
			. " CASE WHEN %s >= {$fresh} THEN '{$inProgressState}' ELSE '{$unfinishedState}' END"
			. " WHEN {$canceled} THEN '{$canceledState}'"
			. " WHEN {$done} THEN '{$doneState}'"
			. " ELSE '' END"
		;

		return new ExpressionField('STATE', $expression, ['STATUS', 'CREATED_AT', 'CREATED_AT']);
	}

	/**
	 * The field the freshness is read from differs by state on purpose. A successful job is fresh by
	 * the moment of the answer itself, the rest by the moment they were created: otherwise a job
	 * created thirty hours ago and finished an hour ago would leave the verdict window, and the page
	 * would report a broken exchange right above the line telling the last answer came an hour ago.
	 */
	private function createRecencyField(DateTime $verdictBoundary): ExpressionField
	{
		$fresh = $this->toSqlDateTime($verdictBoundary);
		$done = JobStatus::DONE->value;

		$expression = "CASE WHEN %s = {$done}"
			. " THEN CASE WHEN %s >= {$fresh} THEN 1 ELSE 0 END"
			. " ELSE CASE WHEN %s >= {$fresh} THEN 1 ELSE 0 END END"
		;

		return new ExpressionField('IS_RECENT', $expression, ['STATUS', 'FINISHED_AT', 'CREATED_AT']);
	}

	/**
	 * A cancel made from the mapping wizard does not collect resends and means a person stopped
	 * waiting, so only a cancel of the agent counts as a sign of a broken exchange.
	 */
	private function createAgentCancelField(): ExpressionField
	{
		$canceled = JobStatus::CANCELED->value;
		$exhausted = JobKillerService::MAX_EVENT_COUNT_BEFORE_CANCEL;

		$expression = "CASE WHEN %s = {$canceled} AND %s >= {$exhausted} THEN 1 ELSE 0 END";

		return new ExpressionField('IS_AGENT_CANCEL', $expression, ['STATUS', 'EVENT_COUNT']);
	}

	/**
	 * Only a successful job counts as an answer, so a cancel never passes for one. The window is the
	 * one of the summary and not of the verdict: a job created earlier than a day ago and answered an
	 * hour ago is exactly the answer the page has to show.
	 */
	private function createLastAnswerField(): ExpressionField
	{
		$done = JobStatus::DONE->value;

		$expression = "MAX(CASE WHEN %s = {$done} THEN %s END)";

		return (new ExpressionField('LAST_ANSWER_AT', $expression, ['STATUS', 'FINISHED_AT']))
			->configureValueType(DatetimeField::class)
		;
	}

	/**
	 * When the last successfully finished job was created, not when it finished.
	 *
	 * This is what proves a recovered exchange: a job born after the trouble and carried through means
	 * the channel works now. The moment it finished proves nothing by itself - a job created before the
	 * trouble could finish after it, and the page would call a dead exchange alive.
	 */
	private function createLastSuccessStartField(): ExpressionField
	{
		$done = JobStatus::DONE->value;

		$expression = "MAX(CASE WHEN %s = {$done} THEN %s END)";

		return (new ExpressionField(
			'LAST_SUCCESS_STARTED_AT',
			$expression,
			['STATUS', 'CREATED_AT'],
		))->configureValueType(DatetimeField::class);
	}

	/**
	 * The last attempt of the exchange that stayed unanswered and ended in a cancel of the agent.
	 *
	 * A cancel carries no moment of its own: updateStatusByIds() writes STATUS alone, FINISHED_AT stays
	 * empty, and the last write to the job is the resend that preceded the cancel. The moment is used as
	 * it is, without correcting it by the TTL of the agent: a cancel made from the mapping wizard can
	 * follow a resend at once, and a correction would push its moment into a future the exchange has
	 * already recovered in.
	 *
	 * GREATEST takes the later of two columns, one of which both MySQL and PostgreSQL may see empty,
	 * hence COALESCE inside.
	 */
	private function createLastCancelAttemptField(): ExpressionField
	{
		$canceled = JobStatus::CANCELED->value;
		$exhausted = JobKillerService::MAX_EVENT_COUNT_BEFORE_CANCEL;

		$expression = "MAX(CASE WHEN %s = {$canceled} AND %s >= {$exhausted}"
			. " THEN GREATEST(COALESCE(%s, %s), %s) END)"
		;

		return (new ExpressionField(
			'LAST_CANCEL_ATTEMPT_AT',
			$expression,
			['STATUS', 'EVENT_COUNT', 'UPDATED_AT', 'CREATED_AT', 'CREATED_AT'],
		))->configureValueType(DatetimeField::class);
	}

	/**
	 * The oldest kind of trouble that is still going on: a job nobody has picked up. It is dated by its
	 * creation and needs no correction - the trouble started the moment the job was born unanswered.
	 */
	private function createLastStuckField(DateTime $stuckBefore): ExpressionField
	{
		$started = JobStatus::STARTED->value;
		$stuck = $this->toSqlDateTime($stuckBefore);

		$expression = "MAX(CASE WHEN %s = {$started} AND %s < {$stuck} THEN %s END)";

		return (new ExpressionField(
			'LAST_STUCK_AT',
			$expression,
			['STATUS', 'CREATED_AT', 'CREATED_AT'],
		))->configureValueType(DatetimeField::class);
	}

	/**
	 * ExpressionField binds no values, so the boundaries are glued into the text of the expression;
	 * the helper gives a literal both MySQL and PostgreSQL read as a moment in time.
	 */
	private function toSqlDateTime(DateTime $dateTime): string
	{
		return Application::getConnection()->getSqlHelper()->convertToDbDateTime($dateTime);
	}

	/**
	 * @return array<int>
	 */
	private function getExcludedTypeValues(): array
	{
		return array_map(static fn(JobType $type) => $type->value, self::EXCLUDED_TYPES);
	}
}
