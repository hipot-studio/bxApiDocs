<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\Integration\AI\Dto\ClientSummaryData;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\GenerateClientSummary;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Type\DateTime;

/**
 * What the tab of the call card is to show about a client, read off the jobs of the queue (ALG-01).
 *
 * The row of the job is the only place a ready summary lives, so the state is derived and never stored:
 * the summary belongs to the pair of a client and a person, because the data behind it is collected
 * with the rights of that person.
 *
 * Nothing is launched here and no right is asked about. Whether this user may read this client is the
 * question of the controller, asked once and at the moment the text is handed out
 * ({@see \Bitrix\Crm\V2\Infrastructure\Controller\AiSummary\CallCard}): a second place to ask would be
 * unreachable, and a reader that refuses would make "no right" and "no summary" one answer.
 *
 * @internal
 */
class ClientSummaryStateReader
{
	/**
	 * How long a finished job is still worth showing, in seconds.
	 *
	 * A client changes between calls, and yesterday's summary may simply lie, so what is shown is only
	 * what was collected recently - the jobs themselves are never deleted, this is a rule of showing
	 * (Q-1). The window is declared here and nowhere else: the controller reads no time of its own.
	 */
	private const FRESHNESS_TTL = 900;

	/**
	 * The state of the tab, and the summary itself when there is one to show.
	 *
	 * @return array{state: ClientSummaryState, summary: ClientSummaryData|null}
	 */
	public function read(ItemIdentifier $client, int $userId): array
	{
		$job = $this->findLastJob($client, $userId);
		if ($job === null)
		{
			return $this->stateOf(ClientSummaryState::None);
		}

		if ($job->getExecutionStatus() !== QueueTable::EXECUTION_STATUS_PENDING)
		{
			$reading = $this->readFinishedJob($job);
			if ($reading['state'] !== ClientSummaryState::Failed)
			{
				return $reading;
			}

			return $this->readPairBehindTheFailure($client, $userId, $job) ?? $reading;
		}

		// the very rule the launch judges a stuck job by, asked by a call and not by a comparison of its
		// own: two comparisons would let the tab and the button contradict each other
		if (!GenerateClientSummary::isJobFresh($job))
		{
			return $this->readSummaryBehindTheWait($client, $userId, $job) ?? $this->stateOf(ClientSummaryState::None);
		}

		return $this->readSummaryArrivedWhileWaiting($client, $userId, $job)
			?? $this->stateOf(ClientSummaryState::Running)
		;
	}

	/**
	 * The summary of a job that finished after the running one had been queued, when there is one.
	 *
	 * Two jobs of one pair may be pending at once: a launch from the tab joins whatever is running and
	 * a launch from the chat queues a job of its own past it (ADR section 3). The newest row is then
	 * the one of the chat, and the summary the tab was waiting for arrives on the older row - read off
	 * the newest row alone the tab would fall back from the ready summary to the waiting indicator at
	 * the very moment the signal of its own job came in.
	 *
	 * Only an answer that arrived after the running job had been queued wins over it, and only a
	 * summary: a job queued past a finished one is the person asking for a new summary in place of the
	 * one they have (AC-008), and a failure of the older row says nothing about the answer still on
	 * its way.
	 *
	 * Which of the two came first is not asked of their times: see
	 * {@see self::arrivedAfterLaunchOf()} for what settles it and why.
	 *
	 * @return array{state: ClientSummaryState, summary: ClientSummaryData|null}|null
	 */
	private function readSummaryArrivedWhileWaiting(
		ItemIdentifier $client,
		int $userId,
		EO_Queue $runningJob,
	): ?array
	{
		$finishedJob = $this->findLastFinishedJob($client, $userId);
		if ($finishedJob === null)
		{
			return null;
		}

		if (!$this->arrivedAfterLaunchOf($finishedJob, $runningJob))
		{
			return null;
		}

		$reading = $this->readFinishedJob($finishedJob);

		return $reading['state'] === ClientSummaryState::Ready ? $reading : null;
	}

	/**
	 * The summary of the other job of the pair when the newest row has given up waiting.
	 *
	 * The third way into the same pair of rows, after {@see readSummaryArrivedWhileWaiting()} and
	 * {@see readPairBehindTheFailure()}, and the same reasoning: a launch from the tab joins whatever is
	 * running, a launch from the chat queues a job of its own past it (ADR section 3), and the newest row
	 * is then the one of the chat. That row may hang until it is stuck while the answer the tab was
	 * waiting for lands on the older one - and read off the newest row alone the tab would offer to
	 * collect everything again with the summary already in hand, spending another call of the model on
	 * the answer it has.
	 *
	 * Only a summary wins here and only one that arrived after the stuck job had been queued, for the
	 * reason the two branches above have: an older summary is the one the person asked to replace
	 * (AC-008). Nothing else of the pair is looked at - a job still running is not asked about, because
	 * the row that gave up is the newest of the pair and anything older stopped long ago.
	 *
	 * The query is paid on the branch of a stuck job alone, where the tab has nothing else to show.
	 *
	 * @return array{state: ClientSummaryState, summary: ClientSummaryData|null}|null
	 */
	private function readSummaryBehindTheWait(
		ItemIdentifier $client,
		int $userId,
		EO_Queue $staleJob,
	): ?array
	{
		$successfulJob = $this->findLastSuccessfulJob($client, $userId);
		if ($successfulJob === null || !$this->arrivedAfterLaunchOf($successfulJob, $staleJob))
		{
			return null;
		}

		$reading = $this->readFinishedJob($successfulJob);

		return $reading['state'] === ClientSummaryState::Ready ? $reading : null;
	}

	/**
	 * What the other job of the pair says when the newest row of it has failed.
	 *
	 * The mirror of {@see readSummaryArrivedWhileWaiting()} and the same pair of rows. Two jobs of one
	 * pair may be pending at once (ADR section 3), and the newest of them is the one of the chat - so
	 * the chat may fail while the tab is still collecting, or while the answer the tab was waiting for
	 * has already landed on the older row. Read off the newest row alone the tab would say `failed`
	 * to a signal that says `ready`, and keep saying it until the freshness window ran out with the
	 * summary inside it.
	 *
	 * The summary is asked about first, for the reason the waiting branch asks about it first: an
	 * answer in hand beats a wait. A summary that was already there when the failed job was queued is
	 * no answer to it, though - that job is the person asking for a new summary in place of the one
	 * they have (AC-008), and the failure is what came of the asking.
	 *
	 * Both questions cost a query each and are asked on the branch of a failure alone, where there is
	 * nothing else to answer with.
	 *
	 * @return array{state: ClientSummaryState, summary: ClientSummaryData|null}|null
	 */
	private function readPairBehindTheFailure(
		ItemIdentifier $client,
		int $userId,
		EO_Queue $failedJob,
	): ?array
	{
		$successfulJob = $this->findLastSuccessfulJob($client, $userId);
		if ($successfulJob !== null && $this->arrivedAfterLaunchOf($successfulJob, $failedJob))
		{
			$reading = $this->readFinishedJob($successfulJob);
			if ($reading['state'] === ClientSummaryState::Ready)
			{
				return $reading;
			}
		}

		$pendingJob = $this->findLastPendingJob($client, $userId);

		return $pendingJob !== null && GenerateClientSummary::isJobFresh($pendingJob)
			? $this->stateOf(ClientSummaryState::Running)
			: null
		;
	}

	/**
	 * Whether the answer of the finished job came after the other one had been queued.
	 *
	 * The two times cannot answer it. Both are kept to the second, so a second they share puts them in
	 * no order at all - and that shared second is the very case this reading is about, because a launch
	 * follows the answer it replaces by as little as one click.
	 *
	 * So the answer is read off what the launch itself wrote down: the summary this pair already had at
	 * the moment the later job was queued ({@see GenerateClientSummary::extractLaunchAddress()}). The
	 * rows are numbered in the order they are queued, so a higher identifier is an answer that came in
	 * later, and zero is the launch saying the pair had no answer at all - so whatever is found now came
	 * after. The identifiers are compared and not merely told apart, because the row asked about here is
	 * not always the newest finished one: behind a failure it is the newest successful one, and an older
	 * success is exactly the answer the person already had.
	 *
	 * A row seeded before the launch began writing that down falls back to the times, and an equal
	 * second there is read as "before": such a row can only have come from the chat, where two jobs of
	 * one pair are never pending at once, so the summary it shares a second with is the one the person
	 * is asking to replace.
	 */
	private function arrivedAfterLaunchOf(EO_Queue $finishedJob, EO_Queue $laterJob): bool
	{
		$precedingJobId = GenerateClientSummary::extractLaunchAddress($laterJob)['precedingFinishedJobId'];
		if ($precedingJobId !== null)
		{
			return (int)$finishedJob->getId() > $precedingJobId;
		}

		$finishedTime = $finishedJob->getFinishedTime();
		$queuedTime = $laterJob->getCreatedTime();

		return $finishedTime instanceof DateTime
			&& $queuedTime instanceof DateTime
			&& $finishedTime->getTimestamp() > $queuedTime->getTimestamp()
		;
	}

	/**
	 * The freshness window is checked above the branch of the error on purpose: finished means any job
	 * but a pending one, and `FINISHED_TIME` is written for every status except `PENDING`. Were the
	 * window checked below, an old failure would lock the tab in `failed` for good, while an older
	 * result is exactly what is to be ignored.
	 *
	 * @return array{state: ClientSummaryState, summary: ClientSummaryData|null}
	 */
	private function readFinishedJob(EO_Queue $job): array
	{
		if (!$this->isWithinFreshnessWindow($job))
		{
			return $this->stateOf(ClientSummaryState::None);
		}

		if ($job->getExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR)
		{
			return $this->stateOf(ClientSummaryState::Failed);
		}

		// the result of the operation and not the data of the summary: a broken contract of the answer is
		// seen by the sign of success, and the payload is taken out of the result
		$result = GenerateClientSummary::constructResult($job);
		if (!$result->isSuccess())
		{
			return $this->stateOf(ClientSummaryState::Failed);
		}

		$payload = $result->getPayload();

		return $payload instanceof ClientSummaryData
			? ['state' => ClientSummaryState::Ready, 'summary' => $payload]
			: $this->stateOf(ClientSummaryState::Failed)
		;
	}

	/**
	 * Substituted in tests; the reader itself asks the repository and never touches the queue table,
	 * which is internal to the AI integration.
	 */
	protected function findLastJob(ItemIdentifier $client, int $userId): ?EO_Queue
	{
		return JobRepository::getInstance()->getLastClientSummaryJob($client, $userId);
	}

	/**
	 * Asked only while a job is running, so the second query costs nothing on the path where the tab
	 * has nothing to wait for.
	 */
	protected function findLastFinishedJob(ItemIdentifier $client, int $userId): ?EO_Queue
	{
		return JobRepository::getInstance()->getLastFinishedClientSummaryJob($client, $userId);
	}

	/**
	 * Asked only behind a newest row with nothing to show - one that failed or one that gave up waiting.
	 */
	protected function findLastSuccessfulJob(ItemIdentifier $client, int $userId): ?EO_Queue
	{
		return JobRepository::getInstance()->getLastSuccessfulClientSummaryJob($client, $userId);
	}

	/**
	 * Asked only behind a failure that no summary of the pair answered.
	 */
	protected function findLastPendingJob(ItemIdentifier $client, int $userId): ?EO_Queue
	{
		return JobRepository::getInstance()->getLastPendingClientSummaryJob($client, $userId);
	}

	/**
	 * A finished job with no time of finishing on it is shown to nobody: the age of such a row cannot be
	 * told, and of the two mistakes left - an old summary passed off as fresh and a fresh one offered to
	 * be made again - the second one costs a click.
	 */
	private function isWithinFreshnessWindow(EO_Queue $job): bool
	{
		$finishedTime = $job->getFinishedTime();
		if (!($finishedTime instanceof DateTime))
		{
			return false;
		}

		return (time() - $finishedTime->getTimestamp()) <= self::FRESHNESS_TTL;
	}

	/**
	 * @return array{state: ClientSummaryState, summary: ClientSummaryData|null}
	 */
	private function stateOf(ClientSummaryState $state): array
	{
		return ['state' => $state, 'summary' => null];
	}
}
