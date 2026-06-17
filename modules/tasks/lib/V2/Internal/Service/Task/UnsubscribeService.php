<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\V2\Internal\Service\PushService;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Exception;

class UnsubscribeService
{
	public function __construct(
		private readonly PushService $pushService,
	)
	{
	}

	public function unsubscribe(int $taskId, array $excludedUsers): void
	{
		$tags = [
			'TASK_VIEW_' . $taskId,
			'CONTENTVIEWTASK-' . $taskId,
			'UNICOMMENTSTASK_' . $taskId, // find by UNICOMMENTS . TASK
			'UNICOMMENTSEXTENDEDTASK_' . $taskId, // find by UNICOMMENTSEXTENDED . TASK
		];

		foreach ($excludedUsers as $userId)
		{
			$params = ['userId' => $userId];

			foreach ($tags as $tag)
			{
				$this->sendPushEvent($tag, $params);
			}
		}
	}

	protected function sendPushEvent(string $tag, array $params): void
	{
		try
		{
			$this->pushService->addEventByTag($tag, PushCommand::TASK_PULL_UNSUBSCRIBE, $params);
		}
		catch (Exception $exception)
		{
			LogFacade::logThrowable($exception);
		}
	}
}
