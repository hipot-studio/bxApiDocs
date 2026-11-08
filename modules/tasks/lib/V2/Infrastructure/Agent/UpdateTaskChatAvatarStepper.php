<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Event;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Update\Stepper;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Feature\ChatAvatarFeature;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatAvatar;
use Bitrix\Tasks\V2\Internal\Model\TaskChatTable;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;

class UpdateTaskChatAvatarStepper extends Stepper
{
	protected static $moduleId = 'tasks';
	private const BATCH_SIZE = 10;
	private const PROCESSING_CHUNK_SIZE = 10;
	private const MAX_BATCHES_PER_EXECUTION = 2;
	private const PAUSE_SECONDS = 30;
	private const EXECUTION_TIME_BUDGET = 10.0;
	private const MIGRATION_VERSION_OPTION = 'chat_avatar_migration_version';
	private const MIGRATION_RESET_REQUIRED_OPTION = 'chat_avatar_migration_reset_required';
	private const MIGRATION_VERSION = 4;
	private const MIGRATION_LOCK = 'tasks_chat_avatar_migration';
	private const MIGRATION_LOCK_TIMEOUT = 5;
	private const MIGRATION_LOCK_ATTEMPTS = 3;
	private const MAX_UPDATE_ATTEMPTS = 3;
	private const RETRY_INTERVAL = 60;
	private const OPTION_STEPPER_STATUS = 'updateTaskChatAvatarStepperStatus';
	private const OPTION_STEPPER_FINISHED = 'updateTaskChatAvatarStepperFinished';
	private TaskStatusMapper $taskStatusMapper;
	private ChatAvatar $chatAvatar;
	private Chat $chat;
	private float $executionStartedAt = 0.0;
	private int $processedTasksCount = 0;
	private bool $timeBudgetReached = false;

	public function __construct(
		?TaskStatusMapper $taskStatusMapper = null,
		?ChatAvatar $chatAvatar = null,
		?Chat $chat = null,
	)
	{
		$container = Container::getInstance();

		$this->taskStatusMapper = $taskStatusMapper ?? $container->get(TaskStatusMapper::class);
		$this->chatAvatar = $chatAvatar ?? $container->get(ChatAvatar::class);
		$this->chat = $chat ?? $container->get(Chat::class);
	}

	public static function execAgent()
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::MIGRATION_LOCK, 0))
		{
			global $pPERIOD;
			$pPERIOD = self::RETRY_INTERVAL;
			self::resetAgentNextExecution();

			return self::class . '::execAgent();';
		}

		try
		{
			$agent = parent::execAgent();
			if (self::isFeatureEnabled() && self::isMigrationResetRequired())
			{
				self::resetMigrationState(removeCanonicalAgent: false);

				global $pPERIOD;
				$pPERIOD = 600;

				$agent = self::getCanonicalAgentName();
			}
			if ($agent === '' && self::isMigrationRequired())
			{
				self::resetMigrationState(removeCanonicalAgent: false);

				global $pPERIOD;
				$pPERIOD = 600;

				$agent = self::getCanonicalAgentName();
			}
			if ($agent !== '')
			{
				self::resetAgentNextExecution();
			}

			return $agent;
		}
		finally
		{
			$connection->unlock(self::MIGRATION_LOCK);
		}
	}

	public static function onAfterSetOption(Event $event): void
	{
		(new ChatAvatarFeatureInitializer())->onAfterSetOption($event);
	}

	public static function markMigrationForRepair(): void
	{
		Option::set(
			self::$moduleId,
			self::MIGRATION_VERSION_OPTION,
			(string)(self::MIGRATION_VERSION - 1),
		);
		Option::set(self::$moduleId, self::MIGRATION_RESET_REQUIRED_OPTION, 'Y');
	}

	public static function scheduleMigration(): void
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			self::scheduleMigrationOnMaster();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function scheduleMigrationOnMaster(): void
	{
		$connection = Application::getConnection();
		$locked = false;

		for ($attempt = 0; $attempt < self::MIGRATION_LOCK_ATTEMPTS; $attempt++)
		{
			$locked = $connection->lock(self::MIGRATION_LOCK, self::MIGRATION_LOCK_TIMEOUT);
			if ($locked)
			{
				break;
			}
			if (
				self::isMigrationCompleted()
				|| (self::isFeatureEnabledOnCurrentConnection() && self::hasValidMigrationSchedule())
			)
			{
				return;
			}
		}

		if (!$locked)
		{
			throw new SystemException('Could not acquire task chat avatar migration lock.');
		}

		try
		{
			if (self::isMigrationCompleted())
			{
				self::removeMigrationAgents();

				return;
			}
			if (!self::isFeatureEnabledOnCurrentConnection())
			{
				throw new SystemException('Task chat avatar feature was disabled before migration scheduling.');
			}
			if (self::hasValidMigrationSchedule())
			{
				return;
			}

			self::removeMigrationAgents();
			self::resetMigrationState(removeCanonicalAgent: false);
			self::bind(600);

			if (self::hasValidMigrationSchedule())
			{
				return;
			}

			throw new SystemException(
				'Could not register task chat avatar migration agent. Actual state: '
				. self::formatMigrationScheduleState(),
			);
		}
		finally
		{
			$connection->unlock(self::MIGRATION_LOCK);
		}
	}

	public static function reconcileSchedulingFailure(callable $rollback): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			return self::reconcileSchedulingFailureOnMaster($rollback);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function reconcileSchedulingFailureOnMaster(callable $rollback): bool
	{
		$connection = Application::getConnection();
		$locked = false;

		do
		{
			$locked = $connection->lock(self::MIGRATION_LOCK, self::MIGRATION_LOCK_TIMEOUT);
			if ($locked)
			{
				break;
			}
			if (self::isFeatureEnabledOnCurrentConnection() && self::isMigrationSchedulingConfirmed())
			{
				return true;
			}
		}
		while (!$locked);

		try
		{
			if (self::isFeatureEnabledOnCurrentConnection() && self::isMigrationSchedulingConfirmed())
			{
				return true;
			}

			$rollback();

			return false;
		}
		finally
		{
			$connection->unlock(self::MIGRATION_LOCK);
		}
	}

	private static function isMigrationSchedulingConfirmed(): bool
	{
		return self::isMigrationCompleted() || self::hasValidMigrationSchedule();
	}

	private static function isMigrationCompleted(): bool
	{
		return !self::isMigrationResetRequiredOnCurrentConnection()
			&& (int)self::getOptionValueOnCurrentConnection(
				self::$moduleId,
				self::MIGRATION_VERSION_OPTION,
			) >= self::MIGRATION_VERSION;
	}

	private static function hasValidMigrationSchedule(): bool
	{
		$state = self::getMigrationScheduleState();
		if (
			$state['resetRequired']
			|| $state['legacyStepperOption'] !== ''
			|| (int)($state['stepperOption']['migrationVersion'] ?? 0) !== self::MIGRATION_VERSION
			|| count($state['agents']) !== 1
		)
		{
			return false;
		}

		$agent = $state['agents'][0];

		return $agent['NAME'] === self::getCanonicalAgentName()
			&& $agent['MODULE_ID'] === self::$moduleId
			&& $agent['ACTIVE'] === 'Y';
	}

	private static function isFeatureEnabledFresh(): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			return self::isFeatureEnabledOnCurrentConnection();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function isFeatureEnabledOnCurrentConnection(): bool
	{
		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'SELECT ?# FROM ?# WHERE ?# = ?s',
			'ENABLED',
			'b_feature_flag',
			'CODE',
			ChatAvatarFeature::class,
		))->compile();
		$value = $connection->queryScalar($sql);

		return $value === null
			? self::getOptionValueOnCurrentConnection(self::$moduleId, 'chat_avatar_feature', 'N') === 'Y'
			: $value === 'Y';
	}

	private static function isMigrationResetRequiredOnCurrentConnection(): bool
	{
		return self::getOptionValueOnCurrentConnection(
			self::$moduleId,
			self::MIGRATION_RESET_REQUIRED_OPTION,
			'N',
		) === 'Y';
	}

	private static function getOptionValueOnCurrentConnection(
		string $moduleId,
		string $name,
		string $default = '',
	): string
	{
		$moduleId = mb_strtolower($moduleId);
		$name = mb_strtolower($name);

		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'SELECT ?# FROM ?# WHERE ?# = ?s AND ?# = ?s',
			'VALUE',
			'b_option',
			'MODULE_ID',
			$moduleId,
			'NAME',
			$name,
		))->compile();
		$value = $connection->queryScalar($sql);

		return $value === null ? $default : (string)$value;
	}

	private static function isMigrationRequired(): bool
	{
		if (!self::isFeatureEnabled())
		{
			return false;
		}

		return self::isMigrationResetRequired()
			|| (int)Option::get(self::$moduleId, self::MIGRATION_VERSION_OPTION, '0') < self::MIGRATION_VERSION;
	}

	private static function isFeatureEnabled(): bool
	{
		return Feature::isEnabled(ChatAvatarFeature::class);
	}

	private static function isMigrationResetRequired(): bool
	{
		return Option::get(self::$moduleId, self::MIGRATION_RESET_REQUIRED_OPTION, 'N') === 'Y';
	}

	private static function resetMigrationState(bool $removeCanonicalAgent = true): void
	{
		$stepperClass = self::class;
		$legacyStepperClass = '\\' . $stepperClass;
		$stepperOptionModule = 'main.stepper.' . self::$moduleId;

		Option::delete(self::$moduleId, ['name' => self::OPTION_STEPPER_STATUS]);
		Option::delete(self::$moduleId, ['name' => self::OPTION_STEPPER_FINISHED]);
		Option::delete(self::$moduleId, ['name' => self::MIGRATION_RESET_REQUIRED_OPTION]);
		Option::delete($stepperOptionModule, ['name' => $stepperClass]);
		Option::delete($stepperOptionModule, ['name' => $legacyStepperClass]);

		if ($removeCanonicalAgent)
		{
			\CAgent::RemoveAgent($stepperClass . '::execAgent();', self::$moduleId);
		}
		\CAgent::RemoveAgent($legacyStepperClass . '::execAgent();', self::$moduleId);

		Option::set(
			self::$moduleId,
			self::MIGRATION_VERSION_OPTION,
			(string)(self::MIGRATION_VERSION - 1),
		);
		Option::set($stepperOptionModule, $stepperClass, serialize([
			'migrationVersion' => self::MIGRATION_VERSION,
		]));
	}

	private static function unserializeOption(string $option): array
	{
		if ($option === '')
		{
			return [];
		}

		$option = @unserialize($option, ['allowed_classes' => false]);

		return is_array($option) ? $option : [];
	}

	private static function getMigrationScheduleState(): array
	{
		$stepperOptionModule = 'main.stepper.' . self::$moduleId;
		$stepperOption = self::getOptionValueOnCurrentConnection($stepperOptionModule, self::class);
		$legacyStepperOption = self::getOptionValueOnCurrentConnection(
			$stepperOptionModule,
			'\\' . self::class,
		);

		return [
			'migrationVersion' => (int)self::getOptionValueOnCurrentConnection(
				self::$moduleId,
				self::MIGRATION_VERSION_OPTION,
				'0',
			),
			'resetRequired' => self::isMigrationResetRequiredOnCurrentConnection(),
			'stepperOptionValue' => $stepperOption,
			'stepperOption' => self::unserializeOption($stepperOption),
			'legacyStepperOption' => $legacyStepperOption,
			'legacyStepperOptionData' => self::unserializeOption($legacyStepperOption),
			'agents' => self::getMigrationAgents(),
		];
	}

	private static function getMigrationAgents(): array
	{
		$agents = [];
		foreach ([self::getCanonicalAgentName(), self::getLegacyAgentName()] as $name)
		{
			$result = \CAgent::GetList(
				['ID' => 'ASC'],
				[
					'=NAME' => $name,
					'USER_ID' => 0,
				],
			);

			while ($agent = $result->Fetch())
			{
				$agents[] = $agent;
			}
		}

		usort(
			$agents,
			static fn (array $left, array $right): int => (int)$left['ID'] <=> (int)$right['ID'],
		);

		return $agents;
	}

	private static function removeMigrationAgents(): void
	{
		foreach (self::getMigrationAgents() as $agent)
		{
			\CAgent::Delete((int)$agent['ID']);
		}
	}

	private static function formatMigrationScheduleState(): string
	{
		$state = self::getMigrationScheduleState();
		$agents = array_map(
			static fn (array $agent): string => sprintf(
				'#%d name=%s module=%s active=%s',
				(int)$agent['ID'],
				(string)$agent['NAME'],
				(string)$agent['MODULE_ID'],
				(string)$agent['ACTIVE'],
			),
			$state['agents'],
		);

		return sprintf(
			'migrationVersion=%d; resetRequired=%s; canonicalStepperOption=%s:%d; '
			. 'legacyStepperOption=%s:%d; agents=[%s]',
			$state['migrationVersion'],
			$state['resetRequired'] ? 'Y' : 'N',
			$state['stepperOptionValue'] === '' ? 'missing' : 'present',
			(int)($state['stepperOption']['migrationVersion'] ?? 0),
			$state['legacyStepperOption'] === '' ? 'missing' : 'present',
			(int)($state['legacyStepperOptionData']['migrationVersion'] ?? 0),
			implode(', ', $agents),
		);
	}

	private static function getCanonicalAgentName(): string
	{
		return self::class . '::execAgent();';
	}

	private static function resetAgentNextExecution(): void
	{
		$agent = \CAgent::GetList(
			[],
			['=NAME' => self::getCanonicalAgentName(), 'MODULE_ID' => self::$moduleId, 'USER_ID' => 0],
		)->Fetch();
		if ($agent && !\CAgent::Update(
			(int)$agent['ID'],
			['~NEXT_EXEC' => Application::getConnection()->getSqlHelper()->getCurrentDateTimeFunction()],
		))
		{
			throw new SystemException('Could not reschedule task chat avatar migration agent.');
		}
	}

	private static function getLegacyAgentName(): string
	{
		return '\\' . self::getCanonicalAgentName();
	}

	/**
	 * @throws ArgumentException
	 * @throws ArgumentOutOfRangeException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function execute(array &$option): bool
	{
		if (!$this->isFeatureEnabledForExecution())
		{
			return self::FINISH_EXECUTION;
		}
		if (!$this->isFeatureAllowedForExecution())
		{
			global $pPERIOD;
			$pPERIOD = self::RETRY_INTERVAL;

			return self::CONTINUE_EXECUTION;
		}

		$startedAt = $this->getCurrentTime();
		if (($option['pauseUntil'] ?? 0) > $startedAt)
		{
			global $pPERIOD;
			$pPERIOD = max(1, (int)ceil($option['pauseUntil'] - $startedAt));

			return self::CONTINUE_EXECUTION;
		}
		$this->executionStartedAt = $startedAt;
		$status = $this->loadCurrentStatus($option);
		$newStatus = [
			'migrationVersion' => $status['migrationVersion'],
			'count' => $status['count'],
			'steps' => $status['steps'],
			'lastTaskId' => $status['lastTaskId'],
			'finished' => $status['finished'],
		];

		if ($newStatus['finished'])
		{
			$this->finishStepper();

			return self::FINISH_EXECUTION;
		}

		$processedBatches = 0;
		do
		{
			$tasksData = $this->getTasksData((int)$newStatus['lastTaskId'], self::BATCH_SIZE);

			if (empty($tasksData))
			{
				$newStatus['finished'] = true;
				$this->updateCurrentStatus($option, $newStatus);
				$this->finishStepper();

				return self::FINISH_EXECUTION;
			}

			$this->processedTasksCount = 0;
			$this->timeBudgetReached = false;
			$isBatchUpdated = $this->updateAvatarsForTasks($tasksData);
			$processedTasksCount = $this->processedTasksCount;
			if ($isBatchUpdated && $processedTasksCount === 0)
			{
				$processedTasksCount = count($tasksData);
			}

			if ($processedTasksCount > 0)
			{
				$processedTasksData = array_slice($tasksData, 0, $processedTasksCount);
				$newStatus['steps'] += $processedTasksCount;
				$newStatus['lastTaskId'] = min(array_column($processedTasksData, 'ID'));
			}

			if (!$isBatchUpdated)
			{
				$this->updateCurrentStatus($option, $newStatus);

				if ($this->timeBudgetReached)
				{
					return $this->pause($option, $this->getCurrentTime());
				}
				if (!$this->isFeatureEnabledForExecution())
				{
					return self::FINISH_EXECUTION;
				}

				global $pPERIOD;
				$pPERIOD = self::RETRY_INTERVAL;
				$option['pauseUntil'] = $this->getCurrentTime() + self::RETRY_INTERVAL;

				return self::CONTINUE_EXECUTION;
			}

			$newStatus['finished'] = count($tasksData) < self::BATCH_SIZE;
			$processedBatches++;

			$this->updateCurrentStatus($option, $newStatus);

			if ($newStatus['finished'])
			{
				$this->finishStepper();

				return self::FINISH_EXECUTION;
			}
			if (!$this->isFeatureEnabledForExecution())
			{
				return self::FINISH_EXECUTION;
			}
			if (!$this->isFeatureAllowedForExecution())
			{
				global $pPERIOD;
				$pPERIOD = self::RETRY_INTERVAL;

				return self::CONTINUE_EXECUTION;
			}
			$now = $this->getCurrentTime();
			if (
				$this->timeBudgetReached
				|| $processedBatches >= self::MAX_BATCHES_PER_EXECUTION
				|| $now - $startedAt >= self::EXECUTION_TIME_BUDGET
			)
			{
				return $this->pause($option, $now);
			}
		}
		while (true);
	}

	private function pause(array &$option, float $now): bool
	{
		global $pPERIOD;
		$pPERIOD = self::PAUSE_SECONDS;
		$option['pauseUntil'] = $now + self::PAUSE_SECONDS;

		return self::CONTINUE_EXECUTION;
	}

	protected function getCurrentTime(): float
	{
		return microtime(true);
	}

	protected function isFeatureEnabledForExecution(): bool
	{
		return self::isFeatureEnabledFresh();
	}

	protected function isFeatureAllowedForExecution(): bool
	{
		return Feature::isEnabled(ChatAvatarFeature::class);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function loadCurrentStatus(array $option): array
	{
		if (
			(int)($option['migrationVersion'] ?? 0) !== self::MIGRATION_VERSION
			|| !array_key_exists('lastTaskId', $option)
		)
		{
			return [
				'migrationVersion' => self::MIGRATION_VERSION,
				'count' => $this->getTotalTasksCount(),
				'steps' => 0,
				'lastTaskId' => PHP_INT_MAX,
				'finished' => false,
			];
		}

		return [
			'migrationVersion' => (int)($option['migrationVersion'] ?? self::MIGRATION_VERSION),
			'count' => (int)($option['count'] ?? 0),
			'steps' => (int)($option['steps'] ?? 0),
			'lastTaskId' => (int)$option['lastTaskId'],
			'finished' => (bool)($option['finished'] ?? false),
		];
	}

	private function updateCurrentStatus(array &$option, array $status): void
	{
		$option = [
			'migrationVersion' => $status['migrationVersion'],
			'count' => $status['count'],
			'steps' => $status['steps'],
			'lastTaskId' => $status['lastTaskId'],
			'finished' => $status['finished'],
		];
	}

	protected function finishStepper(): void
	{
		if (!$this->isFeatureEnabledForExecution())
		{
			return;
		}

		Option::set(self::$moduleId, self::MIGRATION_VERSION_OPTION, (string)self::MIGRATION_VERSION);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getTotalTasksCount(): int
	{
		$count = TaskTable::query()
			->addSelect(Query::expr()->count('ID'), 'COUNT')
			->registerRuntimeField(
				'CHAT_TASK',
				new Reference(
					'CHAT_TASK',
					TaskChatTable::getEntity(),
					['=this.ID' => 'ref.TASK_ID'],
					['join_type' => 'INNER'],
				),
			)
			->exec()
			->fetch()['COUNT'] ?? 0
		;

		return (int)$count;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getTasksData(int $lastTaskId, int $limit): array
	{
		$query = $this->getTasksDataQuery()
			->where('ID', '<', $lastTaskId)
			->setLimit($limit)
			->setOrder(['ID' => 'DESC'])
		;

		return $query->exec()->fetchAll();
	}

	protected function getTasksDataByIds(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		return $this->getTasksDataQuery()
			->whereIn('ID', $taskIds)
			->exec()
			->fetchAll()
		;
	}

	private function getTasksDataQuery(): Query
	{
		return TaskTable::query()
			->setSelect(['ID', 'DEADLINE', 'STATUS', 'CHAT_ID' => 'CHAT_TASK.CHAT_ID'])
			->registerRuntimeField(
				'CHAT_TASK',
				new Reference(
					'CHAT_TASK',
					TaskChatTable::getEntity(),
					['=this.ID' => 'ref.TASK_ID'],
					['join_type' => 'INNER'],
				),
			)
		;
	}

	protected function updateAvatarsForTasks(array $tasksData): bool
	{
		foreach (array_chunk($tasksData, self::PROCESSING_CHUNK_SIZE) as $tasksChunk)
		{
			if (!$this->updateAvatarsForTaskChunk($tasksChunk))
			{
				return false;
			}

			$this->processedTasksCount += count($tasksChunk);
			if ($this->getCurrentTime() - $this->executionStartedAt >= self::EXECUTION_TIME_BUDGET)
			{
				$this->timeBudgetReached = true;

				return $this->processedTasksCount === count($tasksData);
			}
		}

		return true;
	}

	private function updateAvatarsForTaskChunk(array $tasksData): bool
	{
		$pendingTaskIds = array_map('intval', array_column($tasksData, 'ID'));

		for ($attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; $attempt++)
		{
			if (!$this->isFeatureEnabledForExecution())
			{
				return false;
			}
			if (!$this->isFeatureAllowedForExecution())
			{
				return false;
			}

			$freshTasksData = $this->getTasksDataByIds($pendingTaskIds);
			$appliedTasksData = [];
			foreach ($freshTasksData as $taskData)
			{
				$taskId = (int)$taskData['ID'];
				if ($this->updateAvatar($taskData))
				{
					$appliedTasksData[$taskId] = $taskData;
				}
			}

			$latestTasksData = $this->getTasksDataByIds($pendingTaskIds);
			$pendingTaskIds = [];

			foreach ($latestTasksData as $taskData)
			{
				$taskId = (int)$taskData['ID'];
				if (
					!isset($appliedTasksData[$taskId])
					|| !$this->hasSameAvatarState($appliedTasksData[$taskId], $taskData)
				)
				{
					$pendingTaskIds[] = $taskId;
				}
			}

			if (empty($pendingTaskIds))
			{
				return true;
			}
		}

		return false;
	}

	private function updateAvatar(array $taskData): bool
	{
		$task = $this->mapTask($taskData);

		return $this->chat
			->updateChatAvatar($task, $this->chatAvatar->getTypeByTask($task))
			->isSuccess()
		;
	}

	private function hasSameAvatarState(array $firstTaskData, array $secondTaskData): bool
	{
		$firstTask = $this->mapTask($firstTaskData);
		$secondTask = $this->mapTask($secondTaskData);

		return $firstTask->chatId === $secondTask->chatId
			&& $this->chatAvatar->getTypeByTask($firstTask) === $this->chatAvatar->getTypeByTask($secondTask);
	}

	private function mapTask(array $taskData): Entity\Task
	{
		return new Entity\Task(
			id: (int)$taskData['ID'],
			deadlineTs: $taskData['DEADLINE'] ? $taskData['DEADLINE']->getTimestamp() : null,
			status: $this->taskStatusMapper->mapToEnum((int)$taskData['STATUS']),
			chatId: $taskData['CHAT_ID'] ? (int)$taskData['CHAT_ID'] : null,
		);
	}
}
