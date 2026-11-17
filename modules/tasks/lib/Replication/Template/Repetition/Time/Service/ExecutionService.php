<?php

namespace Bitrix\Tasks\Replication\Template\Repetition\Time\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Replication\AbstractReplicator;
use Bitrix\Tasks\Replication\Template\AbstractParameter;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Time\Enum\RepeatType;
use Bitrix\Tasks\Replication\Template\Time\Factory\ExecutionTimeFactory;
use Bitrix\Tasks\Replication\Template\Time\Service\DeadlineCreationTimeService;
use Bitrix\Tasks\Replication\Template\Repetition\Parameter\ReplicateParameter;
use Bitrix\Tasks\Replication\Replicator\RegularTemplateTaskReplicator;
use Bitrix\Tasks\Replication\RepositoryInterface;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\Util\User;
use CAgent;

class ExecutionService
{
	public const PRIORITY_TEMPLATE = 0;
	public const PRIORITY_AGENT = 1;

	private AbstractParameter $replicateParameter;
	private Result $currentResult;
	private string $agent;
	private int $nextExecutionTimeTS;
	private ?array $agentData = null;

	public function __construct(private RepositoryInterface $repository)
	{
		$this->init();
	}

	private function init(): void
	{
		$this->agent = $this->getCurrentAgentName();
		$this->replicateParameter = new ReplicateParameter($this->repository);
		$this->currentResult = new Result();
	}

	public function getTemplateCurrentExecutionTime(int $priority = self::PRIORITY_TEMPLATE): string
	{
		$executionTime = (string)$this->replicateParameter->get('NEXT_EXECUTION_TIME');
		$agent = $this->getAgent();
		$agentNextExec = $agent['NEXT_EXEC'] ?? '';

		if ($priority === self::PRIORITY_AGENT && !empty($agentNextExec))
		{
			return $agentNextExec;
		}

		return empty($executionTime) ? $agentNextExec : $executionTime;
	}

	/**
	 * Both sources hold a portal-formatted datetime string of the same scheduled moment: agent
	 * NEXT_EXEC comes from CAgent::GetList (DateToCharFunction), NEXT_EXECUTION_TIME is written by
	 * the replicator with the user time disabled. So the string is parsed exactly like every other
	 * consumer parses it - MakeTimeStamp, without a timezone shift - and the produced CREATED_DATE
	 * stays comparable with the already-produced check of the replication checker.
	 */
	public function getTemplateCurrentExecutionDateTime(int $priority = self::PRIORITY_TEMPLATE): ?DateTime
	{
		$executionTime = $this->getTemplateCurrentExecutionTime($priority);
		if ($executionTime === '')
		{
			return null;
		}

		$executionTimeTS = (int)MakeTimeStamp($executionTime);

		return $executionTimeTS > 0 ? DateTime::createFromTimestamp($executionTimeTS) : null;
	}

	public function getAgent(): array
	{
		if (is_null($this->agentData))
		{
			$agent = CAgent::getList([], ['NAME' => $this->agent])->fetch();
			$this->agentData = is_array($agent) ? $agent : [];
		}

		return $this->agentData;
	}

	public function getTemplateNextExecutionTime(string $lastExecutionTime): Result
	{
		$template = $this->repository->getEntity();

		$context = $this->assembleExecutionContext($lastExecutionTime, $template);
		$replicationEndTimeTS = $context['endTS'];

		$regularityType = RegularityType::fromValue($template->getReplicateType());
		if ($regularityType === RegularityType::Deadline)
		{
			$deadlineCreationTime = (new DeadlineCreationTimeService())->calculate(
				$context['baseTS'],
				$context['preferredTS'],
				$context['startTS'],
				$this->replicateParameter
			);

			$this->nextExecutionTimeTS = $deadlineCreationTime->creationTime;
		}
		else
		{
			// schedule (and after_completion until Phase 3 handles it via events).
			// A non-null REPLICATE_TYPE means the template was saved with the unified working-days
			// semantics, which extend the weekend shift of the creation date to monthly/yearly by-date.
			$this->nextExecutionTimeTS = ExecutionTimeFactory::getNextExecutionTime(
				$context['baseTS'],
				$context['preferredTS'],
				$this->replicateParameter,
				$template->getReplicateType() !== null
			);
		}

		if ($this->nextExecutionTimeTS <= 0)
		{
			$this->currentResult->addError(new Error(Loc::getMessage('TASKS_EXECUTION_ILLEGAL_NEXT_TIME')));
			return $this->stopReplication();
		}

		$repeatTill = $this->replicateParameter->get('REPEAT_TILL');

		if (!$repeatTill || $repeatTill === RepeatType::ENDLESS)
		{
			return $this->continueReplication();
		}

		if (
			$repeatTill === RepeatType::DATE
			&& $replicationEndTimeTS > 0
			&& $this->nextExecutionTimeTS > $replicationEndTimeTS
		)
		{
			$this->currentResult->addError(new Error(Loc::getMessage('TASKS_EXECUTION_END_DATE_REACHED')));
			return $this->stopReplication();
		}

		if ($repeatTill === RepeatType::TIMES)
		{
			$neededReplicationCount = (int)$this->replicateParameter->get('TIMES');
			$currentReplicationCount = $template->getTparamReplicationCount();

			if ($currentReplicationCount >= $neededReplicationCount)
			{
				$this->currentResult->addError(new Error(Loc::getMessage('TASKS_EXECUTION_LIMIT_REACHED')));
				return $this->stopReplication();
			}
		}

		return $this->continueReplication();
	}

	/**
	 * Assembles the time inputs shared by the schedule and deadline strategies: creator tz,
	 * preferred HH:MM as seconds-of-day, base timestamp (clamped to replication start) and
	 * the start/end bounds. Extracted verbatim so the schedule path stays byte-for-byte.
	 *
	 * @return array{preferredTS:int, baseTS:int, startTS:int, endTS:int}
	 */
	private function assembleExecutionContext(string $lastExecutionTime, TemplateObject $template): array
	{
		// deprecated, TIMEZONE_OFFSET parameter is always 0 in new templates
		$timeZoneOffsetTS = $this->replicateParameter->get('TIMEZONE_OFFSET');
		$creatorTimeZoneOffsetTS = $timeZoneOffsetTS ?? User::getTimeZoneOffset($template->getCreatedBy());

		// prepare time to be forced to
		$replicationTimeTS = strtotime($this->replicateParameter->get('TIME'));
		$creatorPreferredTimeTS = UI::parseTimeAmount(
			date("H:i", $replicationTimeTS - $creatorTimeZoneOffsetTS), 'HH:MI'
		);

		// prepare base time
		$baseExecutionTimeTS = time();

		if ($lastExecutionTime) // agent were found and had legal next_time
		{
			$lastExecutionTimeTS = (int)MakeTimeStamp($lastExecutionTime);
			if ($lastExecutionTimeTS > 0)
			{
				$baseExecutionTimeTS = $lastExecutionTimeTS;
			}
		}

		$replicationStartTimeTS = 0;
		$replicationStartTime = $this->replicateParameter->get('START_DATE');
		if ($replicationStartTime)
		{
			$replicationStartTimeTS = MakeTimeStamp($replicationStartTime);
			$replicationStartTimeTS -= $creatorTimeZoneOffsetTS;
		}

		$replicationEndTimeTS = PHP_INT_MAX; // never ending
		$replicationEndTime = $this->replicateParameter->get('END_DATE');
		if ($replicationEndTime)
		{
			$replicationEndTimeTS = MakeTimeStamp($replicationEndTime);
			$replicationEndTimeTS -= $creatorTimeZoneOffsetTS;
		}

		$baseExecutionTimeTS = max($baseExecutionTimeTS, $replicationStartTimeTS);

		return [
			'preferredTS' => $creatorPreferredTimeTS,
			'baseTS' => $baseExecutionTimeTS,
			'startTS' => $replicationStartTimeTS,
			'endTS' => $replicationEndTimeTS,
		];
	}

	/**
	 * Deadline (server-local unix timestamp) of the instance being produced on this run,
	 * for the deadline strategy; null for other strategies or when it cannot be resolved.
	 *
	 * Anchored to the SCHEDULED creation moment (persisted NEXT_EXECUTION_TIME / agent NEXT_EXEC =
	 * deadline − CREATE_OFFSET) — the very value the base producer stamps as CREATED_DATE — and NOT
	 * to "now". At fire time the wall clock has already reached the creation moment, so basing the
	 * deadline search on time() makes the strict-forward factory overshoot to the next period
	 * whenever the creation coincides with the deadline (CREATE_OFFSET = 0, "create on the deadline
	 * day"). The 1-second nudge keeps the "> base" search on the CURRENT cycle in that coincident
	 * case; for CREATE_OFFSET > 0 the creation already precedes the deadline, so the nudge is inert.
	 */
	public function getTemplateCurrentDeadline(): ?int
	{
		$template = $this->repository->getEntity();
		if (is_null($template) || RegularityType::fromValue($template->getReplicateType()) !== RegularityType::Deadline)
		{
			return null;
		}

		$context = $this->assembleExecutionContext($this->getTemplateCurrentExecutionTime(), $template);
		$baseTS = max(0, $context['baseTS'] - 1);

		return (new DeadlineCreationTimeService())->calculate(
			$baseTS,
			$context['preferredTS'],
			$context['startTS'],
			$this->replicateParameter,
		)->deadline;
	}

	public function getCurrentAgentName(): string
	{
		$template = $this->repository->getEntity();
		if (is_null($template))
		{
			return '';
		}

		return str_replace('#ID#', $template->getId(), RegularTemplateTaskReplicator::AGENT_TEMPLATE);
	}

	public function getParameter(): AbstractParameter
	{
		return $this->replicateParameter;
	}

	private function stopReplication(): Result
	{
		$this->currentResult->setData(['time' => 0]);
		return $this->currentResult;
	}

	private function continueReplication(): Result
	{
		$this->currentResult->setData(['time' => $this->nextExecutionTimeTS]);
		return $this->currentResult;
	}
}
