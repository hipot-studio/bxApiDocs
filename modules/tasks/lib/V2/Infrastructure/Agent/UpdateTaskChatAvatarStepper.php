<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Update\Stepper;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatAvatar;
use Bitrix\Tasks\V2\Internal\Model\TaskChatTable;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;

class UpdateTaskChatAvatarStepper extends Stepper
{
	protected static $moduleId = 'tasks';
	private const BATCH_SIZE = 50;
	private const OPTION_STEPPER_STATUS = 'updateTaskChatAvatarStepperStatus';
	private const OPTION_STEPPER_FINISHED = 'updateTaskChatAvatarStepperFinished';
	private TaskStatusMapper $taskStatusMapper;
	private ChatAvatar $chatAvatar;
	private Chat $chat;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->taskStatusMapper = $container->get(TaskStatusMapper::class);
		$this->chatAvatar = $container->get(ChatAvatar::class);
		$this->chat = $container->get(Chat::class);
	}

	/**
	 * @throws ArgumentException
	 * @throws ArgumentOutOfRangeException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function execute(array &$option): bool
	{
		$status = $this->loadCurrentStatus();
		$newStatus = [
			'count' => $status['count'],
			'steps' => $status['steps'],
			'lastTaskId' => $status['lastTaskId'],
			'finished' => $status['finished'],
		];

		if ($newStatus['finished'])
		{
			return self::FINISH_EXECUTION;
		}

		$tasksData = $this->getTasksData((int)$newStatus['lastTaskId'], self::BATCH_SIZE);

		$processed = $this->updateAvatarsForTasks($tasksData);

		if (!empty($tasksData))
		{
			$newStatus['steps'] += $processed;
			$newStatus['lastTaskId'] = min(array_column($tasksData, 'ID'));
			if (count($tasksData) < self::BATCH_SIZE)
			{
				$newStatus['finished'] = true;
			}
		}
		else
		{
			$newStatus['finished'] = true;
		}

		$this->updateCurrentStatus($option, $newStatus);

		if ($processed > 0 && !$newStatus['finished'])
		{
			return self::CONTINUE_EXECUTION;
		}

		$this->finishStepper($newStatus);

		return self::FINISH_EXECUTION;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function loadCurrentStatus(): array
	{
		$status = Option::get(self::$moduleId, self::OPTION_STEPPER_STATUS, 'default');
		$status = ($status !== 'default' ? @unserialize($status, ['allowed_classes' => false]) : []);
		$status = (is_array($status) ? $status : []);

		if (empty($status))
		{
			$status = [
				'count' => $this->getTotalTasksCount(),
				'steps' => 0,
				'lastTaskId' => PHP_INT_MAX,
				'finished' => false,
			];
		}

		return $status;
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	private function updateCurrentStatus(array &$option, array $status): void
	{
		Option::set(self::$moduleId, self::OPTION_STEPPER_STATUS, serialize($status));

		$option = [
			'count' => $status['count'],
			'steps' => $status['steps'],
			'lastTaskId' => $status['lastTaskId'],
			'finished' => $status['finished'],
		];
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	private function finishStepper(array $status): void
	{
		$status['finished'] = true;

		Option::set(self::$moduleId, self::OPTION_STEPPER_STATUS, serialize($status));
		Option::set(self::$moduleId, self::OPTION_STEPPER_FINISHED, 'Y');
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getTotalTasksCount(): int
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
	private function getTasksData(int $lastTaskId, int $limit): array
	{
		$query = TaskTable::query()
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
			->where('ID', '<', $lastTaskId)
			->setLimit($limit)
			->setOrder(['ID' => 'DESC'])
		;

		return $query->exec()->fetchAll();
	}

	private function updateAvatarsForTasks(array $tasksData): int
	{
		$processed = 0;

		foreach ($tasksData as $taskData)
		{
			$task = new Entity\Task(
				id: (int)$taskData['ID'],
				deadlineTs: $taskData['DEADLINE'] ? $taskData['DEADLINE']->getTimestamp() : null,
				status: $this->taskStatusMapper->mapToEnum((int)$taskData['STATUS']),
				chatId: $taskData['CHAT_ID'] ? (int)$taskData['CHAT_ID'] : null,
			);

			$chatAvatarType = $this->chatAvatar->getTypeByTask($task);

			$this->chat->updateChatAvatar($task, $chatAvatarType);

			$processed++;
		}

		return $processed;
	}
}
