<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\MigrateRecentTasks;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;

class MigrateRecentTaskBackgroundJob
{
	public const RECENT_LIMIT = 10;

	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly Logger $logger,
	)
	{
	}

	public static function register(?int $userId = null, ?int $limit = null): void
	{
		$userId = $userId ?? self::getCurrentUserId();

		if (self::shouldSkipRegistration($userId))
		{
			return;
		}

		self::addBackgroundJob($userId, $limit ?? self::getRecentLimit());
	}

	public static function run(int $userId, int $limit = 10): void
	{
		$job = Container::getInstance()->get(self::class);
		$job($userId, $limit);
	}

	public function __invoke(int $userId, int $limit = 10): void
	{
		if ($this->hasEnoughTasksWithChat($userId, $limit))
		{
			$this->markAsProcessed($userId);

			return;
		}

		foreach ($this->getTasks($userId, $limit) as $task)
		{
			if (!empty($task['TASKS_INTERNALS_TASK_CHAT_TASK_CHAT_ID']))
			{
				continue;
			}

			$this->sendMessageToQueue((int)$task['ID']);
		}

		$this->markAsProcessed($userId);
	}

	private static function isProcessed(int $userId): bool
	{
		try
		{
			return \CUserOptions::GetOption('tasks', 'migrate_recent_tasks_processed', false, $userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function markAsProcessed(int $userId): void
	{
		try
		{
			\CUserOptions::SetOption('tasks', 'migrate_recent_tasks_processed', true, user_id: $userId);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	private static function getRecentLimit(): int
	{
		return (int)Option::get('tasks', 'migrate_recent_tasks_limit', self::RECENT_LIMIT);
	}

	private static function getCurrentUserId(): ?int
	{
		return (int)CurrentUser::get()->getId();
	}

	public static function shouldSkipRegistration(?int $userId = null): bool
	{
		return empty($userId) || self::isProcessed($userId);
	}

	private static function addBackgroundJob(int $userId, int $limit): void
	{
		Application::getInstance()->addBackgroundJob(
			[self::class, 'run'],
			[
				'userId' => $userId,
				'limit' => $limit,
			]
		);
	}

	private function hasEnoughTasksWithChat(int $userId, int $limit): bool
	{
		return $this->taskRepository->countRecentTaskIdsWithChatIds($userId) >= $limit;
	}

	/**
	 * @param int $userId The ID of the user to get the tasks for.
	 * @param int $limit The limit of the tasks to get.
	 * @return array{ID: int, TASKS_INTERNALS_TASK_CHAT_TASK_CHAT_ID: int|null}[] The tasks.
	 */
	private function getTasks(int $userId, int $limit): array
	{
		return $this->taskRepository->findRecentTaskIdsWithChatIdsOrderedByActivityDate($userId, $limit);
	}

	private function sendMessageToQueue(int $taskId): void
	{
		try
		{
			$message = new MigrateRecentTasksMessage($taskId);

			$message->sendByInternalQueueId();
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}
