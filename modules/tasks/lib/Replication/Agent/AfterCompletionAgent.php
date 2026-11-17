<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Integration\Bitrix24;
use Bitrix\Tasks\Integration\Bitrix24\FeatureDictionary;
use Bitrix\Tasks\Integration\IM\Notification\UseCase\Regularity\RegularTaskReplicationFailed;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Replication\Repository\TemplateRepository;
use Bitrix\Tasks\Replication\Template\AfterCompletionTransitionService;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Repetition\RegularTemplateTaskProducer;
use CAgent;
use Closure;
use CTimeZone;
use Throwable;

/**
 * One-shot agent that creates the next instance of an after_completion template.
 */
final class AfterCompletionAgent
{
	public const AGENT_NAME_TPL = '\\Bitrix\\Tasks\\Replication\\Agent\\AfterCompletionAgent::runTransition(#ID#, #PREDECESSOR_ID#, #ATTEMPT#);';

	private const COUNTED_AGENT_NAME_TPL = '\\Bitrix\\Tasks\\Replication\\Agent\\AfterCompletionAgent::runCountedTransition(#ID#, #PREDECESSOR_ID#, #COUNT#, #ATTEMPT#);';
	private const RECOVERY_AGENT_NAME_TPL = '\\Bitrix\\Tasks\\Replication\\Agent\\AfterCompletionAgent::recoverTransition(#ID#, #PREDECESSOR_ID#, #COUNT#);';
	private const PREVIOUS_AGENT_NAME_TPL = '\\Bitrix\\Tasks\\Replication\\Agent\\AfterCompletionAgent::run(#ID#, #COUNT#);';
	private const LEGACY_AGENT_NAME_TPL = '\\Bitrix\\Tasks\\Replication\\Agent\\AfterCompletionAgent::run(#ID#);';

	private const MODULE_ID = 'tasks';
	private const AGENT_LOOKUP_CHUNK = 100;
	private const MAX_ATTEMPTS = 3;
	private const LOCK_TIMEOUT = 5;
	private const RETRY_INTERVAL = 300;

	private readonly Closure $tariffChecker;
	private readonly Closure $failureNotifier;

	public function __construct(
		?Closure $tariffChecker = null,
		?Closure $failureNotifier = null,
	)
	{
		$this->tariffChecker = $tariffChecker ?? static fn (): bool =>
			Bitrix24::checkFeatureEnabled(FeatureDictionary::TASK_RECURRING_TASKS);
		$this->failureNotifier = $failureNotifier ?? static function (int $templateId): void {
			self::notifyReplicationFailed($templateId);
		};
	}

	/**
	 * The replication-count argument is retained for compatibility with already deployed callers.
	 */
	public static function getAgentName(
		int $templateId,
		int $predecessorTaskId = 0,
		int $expectedReplicationCount = 0,
		int $attempt = 0,
	): string
	{
		return str_replace(
			['#ID#', '#PREDECESSOR_ID#', '#ATTEMPT#'],
			[(string)$templateId, (string)$predecessorTaskId, (string)$attempt],
			self::AGENT_NAME_TPL,
		);
	}

	/**
	 * Kept only so an agent saved by an earlier version can be found and removed.
	 */
	public static function getRecoveryAgentName(
		int $templateId,
		int $predecessorTaskId,
		int $expectedReplicationCount,
	): string
	{
		return str_replace(
			['#ID#', '#PREDECESSOR_ID#', '#COUNT#'],
			[(string)$templateId, (string)$predecessorTaskId, (string)$expectedReplicationCount],
			self::RECOVERY_AGENT_NAME_TPL,
		);
	}

	public static function exists(int $templateId, ?int $predecessorTaskId = null): bool
	{
		if (self::findAgent($templateId, $predecessorTaskId) !== null)
		{
			return true;
		}

		$previousAgent = CAgent::getList([], [
			'MODULE_ID' => self::MODULE_ID,
			'NAME' => self::getPreviousAgentNamePattern($templateId),
		])->fetch();
		if (is_array($previousAgent))
		{
			return true;
		}

		return is_array(CAgent::getList([], [
			'MODULE_ID' => self::MODULE_ID,
			'=NAME' => self::getLegacyAgentName($templateId),
		])->fetch());
	}

	public static function getNextExecTs(int $templateId): ?int
	{
		return self::getNextExecTsByTemplateIds([$templateId])[$templateId] ?? null;
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, int> [templateId => next execution timestamp]
	 */
	public static function getNextExecTsByTemplateIds(array $templateIds): array
	{
		$templateIds = array_values(
			array_unique(
				array_filter(
					array_map('intval', $templateIds),
					static fn (int $templateId): bool => $templateId > 0,
				),
			),
		);
		if ($templateIds === [])
		{
			return [];
		}

		CTimeZone::Disable();
		try
		{
			$agents = self::findActiveAgentsForTemplates($templateIds);
		}
		finally
		{
			CTimeZone::Enable();
		}

		$requested = array_fill_keys($templateIds, true);
		$result = [];
		foreach ($agents as $agent)
		{
			$templateId = self::extractTemplateIdFromAgentName((string)($agent['NAME'] ?? ''));
			if (!isset($requested[$templateId]) || empty($agent['NEXT_EXEC']))
			{
				continue;
			}

			$nextExec = $agent['NEXT_EXEC'];
			$timestamp = $nextExec instanceof DateTime
				? $nextExec->getTimestamp()
				: (int)MakeTimeStamp($nextExec)
			;
			if ($timestamp <= 0)
			{
				continue;
			}

			if (!isset($result[$templateId]) || $timestamp < $result[$templateId])
			{
				$result[$templateId] = $timestamp;
			}
		}

		return $result;
	}

	public static function arm(
		int $templateId,
		int $predecessorTaskId,
		int $runAtTs,
		?int $expectedReplicationCount = null,
	): void
	{
		$runAt = DateTime::createFromTimestamp($runAtTs)->disableUserTime()->toString();

		CTimeZone::Disable();
		try
		{
			$agentId = CAgent::AddAgent(
				self::getAgentName($templateId, $predecessorTaskId),
				self::MODULE_ID,
				'N',
				self::RETRY_INTERVAL,
				'',
				'Y',
				$runAt,
				100,
				false,
				false,
			);
			if ($agentId === false)
			{
				self::notifyReplicationFailedSafelyStatic($templateId);

				throw new SystemException(
					"Unable to arm after-completion transition for template {$templateId}",
				);
			}
		}
		finally
		{
			CTimeZone::Enable();
		}
	}

	public static function removeAll(int $templateId): void
	{
		foreach (self::findAgents($templateId) as $agent)
		{
			CAgent::Delete((int)$agent['ID']);
		}
	}

	/**
	 * Compatibility entry point for agents armed before predecessor tokens were introduced.
	 */
	public static function run($templateId = 0, $expectedReplicationCount = null): string
	{
		$templateId = (int)$templateId;
		if ($templateId <= 0)
		{
			return '';
		}

		$repository = TemplateRepository::getInstance($templateId);
		$repository->drop();
		$template = $repository->getEntity();
		if ($template === null)
		{
			return '';
		}

		$predecessorTaskId = (new AfterCompletionTransitionService())->getTailTaskId($template);

		return (new self())->executeTransition($templateId, $predecessorTaskId);
	}

	public static function runTransition(
		$templateId = 0,
		$predecessorTaskId = 0,
		$attempt = 0,
	): string
	{
		return (new self())->executeTransition(
			(int)$templateId,
			(int)$predecessorTaskId,
			null,
			(int)$attempt,
		);
	}

	/**
	 * Compatibility entry point for agents that persist the count observed before the transition.
	 */
	public static function runCountedTransition(
		$templateId = 0,
		$predecessorTaskId = 0,
		$expectedReplicationCount = 0,
		$attempt = 0,
	): string
	{
		return (new self())->executeTransition(
			(int)$templateId,
			(int)$predecessorTaskId,
			max(0, (int)$expectedReplicationCount),
			(int)$attempt,
		);
	}

	/**
	 * Persistent recovery agents are no longer supported. A stale agent self-removes.
	 */
	public static function recoverTransition(
		$templateId = 0,
		$predecessorTaskId = 0,
		$expectedReplicationCount = 0,
	): string
	{
		return '';
	}

	/**
	 * @internal Public for tests around tariff and notification integrations.
	 */
	public function executeTransition(
		int $templateId,
		int $predecessorTaskId,
		?int $expectedReplicationCount = null,
		int $attempt = 0,
	): string
	{
		$predecessorTaskId = max(0, $predecessorTaskId);
		$attempt = max(0, $attempt);
		if ($templateId <= 0 || !Feature::isEnabled(RegularTaskTypesFeature::class))
		{
			return '';
		}

		if (!(($this->tariffChecker)()))
		{
			LogFacade::log(['templateId' => $templateId], 'TASKS_REPLICATION_RESTRICTED');
			$result = (new Result())->addError(
				new Error("Recurring tasks are restricted for template {$templateId}"),
			);
		}
		else
		{
			$result = self::produce(
				$templateId,
				$predecessorTaskId,
				$expectedReplicationCount,
			);
		}

		if ($result->isSuccess())
		{
			return '';
		}

		if ($attempt === 0)
		{
			$this->notifyReplicationFailedSafely($templateId);
		}

		$nextAttempt = $attempt + 1;
		if (
			$nextAttempt < self::MAX_ATTEMPTS
			&& self::isTransitionStillPending($templateId, $predecessorTaskId)
		)
		{
			return self::getAgentName(
				$templateId,
				$predecessorTaskId,
				0,
				$nextAttempt,
			);
		}

		return '';
	}

	private static function getLegacyAgentName(int $templateId): string
	{
		return str_replace('#ID#', (string)$templateId, self::LEGACY_AGENT_NAME_TPL);
	}

	private static function getPreviousAgentNamePattern(int $templateId): string
	{
		return str_replace(
			['#ID#', '#COUNT#'],
			[(string)$templateId, '%'],
			self::PREVIOUS_AGENT_NAME_TPL,
		);
	}

	private static function getAgentNamePattern(int $templateId, ?int $predecessorTaskId): string
	{
		return str_replace(
			['#ID#', '#PREDECESSOR_ID#', '#ATTEMPT#'],
			[
				(string)$templateId,
				$predecessorTaskId === null ? '%' : (string)$predecessorTaskId,
				'%',
			],
			self::AGENT_NAME_TPL,
		);
	}

	private static function getCountedAgentNamePattern(
		int $templateId,
		?int $predecessorTaskId,
	): string
	{
		return str_replace(
			['#ID#', '#PREDECESSOR_ID#', '#COUNT#', '#ATTEMPT#'],
			[
				(string)$templateId,
				$predecessorTaskId === null ? '%' : (string)$predecessorTaskId,
				'%',
				'%',
			],
			self::COUNTED_AGENT_NAME_TPL,
		);
	}

	private static function getRecoveryAgentNamePattern(
		int $templateId,
		?int $predecessorTaskId,
	): string
	{
		return str_replace(
			['#ID#', '#PREDECESSOR_ID#', '#COUNT#'],
			[
				(string)$templateId,
				$predecessorTaskId === null ? '%' : (string)$predecessorTaskId,
				'%',
			],
			self::RECOVERY_AGENT_NAME_TPL,
		);
	}

	private static function findAgent(int $templateId, ?int $predecessorTaskId): ?array
	{
		$agents = self::findAgentsByPatterns([
			self::getAgentNamePattern($templateId, $predecessorTaskId),
			self::getCountedAgentNamePattern($templateId, $predecessorTaskId),
			self::getRecoveryAgentNamePattern($templateId, $predecessorTaskId),
		]);
		usort(
			$agents,
			static fn (array $first, array $second): int =>
				(string)($first['NEXT_EXEC'] ?? '') <=> (string)($second['NEXT_EXEC'] ?? ''),
		);
		$agent = $agents[0] ?? null;

		return is_array($agent) ? $agent : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function findAgents(int $templateId): array
	{
		$agents = [];
		foreach (
			[
				self::getAgentNamePattern($templateId, null),
				self::getCountedAgentNamePattern($templateId, null),
				self::getRecoveryAgentNamePattern($templateId, null),
				self::getPreviousAgentNamePattern($templateId),
				self::getLegacyAgentName($templateId),
			]
			as $name
		)
		{
			$result = CAgent::getList([], [
				'MODULE_ID' => self::MODULE_ID,
				'NAME' => $name,
			]);
			while ($agent = $result->fetch())
			{
				$agents[(int)$agent['ID']] = $agent;
			}
		}

		return array_values($agents);
	}

	/**
	 * @param string[] $patterns
	 * @return array<int, array<string, mixed>>
	 */
	private static function findAgentsByPatterns(array $patterns): array
	{
		$agents = [];
		foreach ($patterns as $pattern)
		{
			$result = CAgent::getList([], [
				'MODULE_ID' => self::MODULE_ID,
				'NAME' => $pattern,
			]);
			while ($agent = $result->fetch())
			{
				$agents[(int)$agent['ID']] = $agent;
			}
		}

		return array_values($agents);
	}

	/**
	 * Reads the armed transitions for a slice. There is no ORM table for b_agent, so a tracked
	 * query is used instead of one CAgent lookup per template. Each template contributes five
	 * name conditions, so the slice is split into chunks: a single statement for the whole
	 * agenda cap would carry tens of thousands of OR-ed conditions.
	 *
	 * @param int[] $templateIds
	 * @return array<int, array<string, mixed>>
	 */
	private static function findActiveAgentsForTemplates(array $templateIds): array
	{
		$agents = [];
		foreach (array_chunk($templateIds, self::AGENT_LOOKUP_CHUNK) as $chunk)
		{
			foreach (self::findActiveAgentsForTemplatesChunk($chunk) as $agent)
			{
				$agents[(int)$agent['ID']] = $agent;
			}
		}

		return array_values($agents);
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, array<string, mixed>>
	 */
	private static function findActiveAgentsForTemplatesChunk(array $templateIds): array
	{
		$connection = Application::getInstance()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$conditions = [];
		foreach ($templateIds as $templateId)
		{
			foreach (
				[
					self::getAgentNamePattern($templateId, null),
					self::getCountedAgentNamePattern($templateId, null),
					self::getRecoveryAgentNamePattern($templateId, null),
					self::getPreviousAgentNamePattern($templateId),
				]
				as $pattern
			)
			{
				$likePattern = str_replace('\\', '\\\\', $pattern);
				$conditions[] = "A.NAME LIKE '" . $sqlHelper->forSql($likePattern) . "'";
			}

			$conditions[] = "A.NAME = '" . $sqlHelper->forSql(self::getLegacyAgentName($templateId)) . "'";
		}

		$moduleId = $sqlHelper->forSql(self::MODULE_ID);
		$query = $connection->query(
			"SELECT A.ID, A.NAME, A.NEXT_EXEC
			FROM b_agent A
			WHERE A.MODULE_ID = '{$moduleId}'
				AND A.ACTIVE = 'Y'
				AND (" . implode(' OR ', $conditions) . ')',
		);

		$agents = [];
		while ($agent = $query->fetch())
		{
			$agents[(int)$agent['ID']] = $agent;
		}

		return array_values($agents);
	}

	private static function extractTemplateIdFromAgentName(string $name): int
	{
		foreach (
			[
				self::AGENT_NAME_TPL,
				self::COUNTED_AGENT_NAME_TPL,
				self::RECOVERY_AGENT_NAME_TPL,
				self::PREVIOUS_AGENT_NAME_TPL,
				self::LEGACY_AGENT_NAME_TPL,
			]
			as $template
		)
		{
			$prefix = strstr($template, '#ID#', true);
			if ($prefix !== false && str_starts_with($name, $prefix))
			{
				return (int)substr($name, strlen($prefix));
			}
		}

		return 0;
	}

	private static function produce(
		int $templateId,
		int $predecessorTaskId,
		?int $expectedReplicationCount = null,
	): Result
	{
		$connection = Application::getInstance()->getConnection();
		$lockName = "tasks_after_completion_{$templateId}";
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return (new Result())->addError(
				new Error("Unable to lock after-completion transition for template {$templateId}"),
			);
		}

		try
		{
			if (!self::isTemplateAlive($templateId))
			{
				return new Result();
			}

			$repository = TemplateRepository::getInstance($templateId);
			$repository->drop();
			$template = $repository->getEntity();
			if (
				$template === null
				|| RegularityType::fromValue($template->getReplicateType()) !== RegularityType::AfterCompletion
				|| !$template->getReplicate()
			)
			{
				return new Result();
			}

			$counterResult = self::synchronizeReplicationCount($template, $expectedReplicationCount);
			if (!$counterResult->isSuccess())
			{
				return $counterResult;
			}

			$transitionService = new AfterCompletionTransitionService();
			if (
				$transitionService->isChainFinished($template, time())
				|| !$transitionService->isReady($template, $predecessorTaskId)
			)
			{
				return new Result();
			}

			$producer = new RegularTemplateTaskProducer($repository);
			$producer->setCreatedDate(new DateTime());

			$result = $producer->produceTask();
			$counterResult = self::synchronizeReplicationCount($template, $expectedReplicationCount);
			if (!$counterResult->isSuccess())
			{
				$result->addErrors($counterResult->getErrors());
			}

			return $result;
		}
		catch (Throwable $throwable)
		{
			LogFacade::logThrowable($throwable);

			return (new Result())->addError(new Error($throwable->getMessage()));
		}
		finally
		{
			TemplateRepository::getInstance($templateId)->drop();
			$connection->unlock($lockName);
		}
	}

	private static function isTemplateAlive(int $templateId): bool
	{
		return TemplateTable::getCount([
			'=ID' => $templateId,
			'=ZOMBIE' => 'N',
		]) > 0;
	}

	private static function synchronizeReplicationCount(
		TemplateObject $template,
		?int $expectedReplicationCount,
	): Result
	{
		$result = new Result();
		$currentCount = (int)$template->getTparamReplicationCount();
		$forkedTaskCount = self::getForkedTaskCount((int)$template->getId());
		$countBeforeTransition = max(0, (int)$expectedReplicationCount);

		if ($forkedTaskCount <= max($currentCount, $countBeforeTransition))
		{
			return $result;
		}

		try
		{
			(new Control\Template((int)$template->getCreatedBy()))->update(
				(int)$template->getId(),
				['TPARAM_REPLICATION_COUNT' => $forkedTaskCount],
			);
			$template->setTparamReplicationCount($forkedTaskCount);
		}
		catch (Throwable $throwable)
		{
			$result->addError(new Error($throwable->getMessage()));
		}

		return $result;
	}

	private static function getForkedTaskCount(int $templateId): int
	{
		return TaskTable::getCount([
			'=FORKED_BY_TEMPLATE_ID' => $templateId,
		]);
	}

	private static function isReplicationCountBehind(TemplateObject $template): bool
	{
		return self::getForkedTaskCount((int)$template->getId())
			> (int)$template->getTparamReplicationCount()
		;
	}

	private static function isTransitionStillPending(int $templateId, int $predecessorTaskId): bool
	{
		try
		{
			if (!self::isTemplateAlive($templateId))
			{
				return false;
			}

			$repository = TemplateRepository::getInstance($templateId);
			$repository->drop();
			$template = $repository->getEntity();
			if (
				$template === null
				|| RegularityType::fromValue($template->getReplicateType()) !== RegularityType::AfterCompletion
				|| !$template->getReplicate()
			)
			{
				return false;
			}

			if (self::isReplicationCountBehind($template))
			{
				return true;
			}

			$transitionService = new AfterCompletionTransitionService();

			return !$transitionService->isChainFinished($template, time())
				&& $transitionService->isReady($template, $predecessorTaskId);
		}
		catch (Throwable $throwable)
		{
			LogFacade::logThrowable($throwable);

			return false;
		}
		finally
		{
			TemplateRepository::getInstance($templateId)->drop();
		}
	}

	private function notifyReplicationFailedSafely(int $templateId): void
	{
		try
		{
			($this->failureNotifier)($templateId);
		}
		catch (Throwable $throwable)
		{
			LogFacade::logThrowable($throwable);
		}
	}

	private static function notifyReplicationFailedSafelyStatic(int $templateId): void
	{
		try
		{
			self::notifyReplicationFailed($templateId);
		}
		catch (Throwable $throwable)
		{
			LogFacade::logThrowable($throwable);
		}
	}

	private static function notifyReplicationFailed(int $templateId): void
	{
		$template = TemplateRepository::getInstance($templateId)->getEntity();
		if ($template === null)
		{
			return;
		}

		(new RegularTaskReplicationFailed())->send(
			(int)$template->getCreatedBy(),
			$templateId,
			(string)$template->getTitle(),
		);
	}
}
