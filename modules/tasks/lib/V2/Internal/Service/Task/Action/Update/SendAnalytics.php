<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Analytics;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\Helper;
use DateTimeZone;

class SendAnalytics
{
	use ConfigTrait;

	public function __invoke(Task $taskBefore, Task $taskAfter): void
	{
		$analyticsData = $this->config->getAnalyticsData();

		if ($analyticsData?->event === null || $analyticsData?->section === null)
		{
			return;
		}

		if (!$this->shouldSend($analyticsData->event, $taskBefore, $taskAfter))
		{
			return;
		}

		$parameters = $this->buildParameters($analyticsData->event, $taskAfter, $analyticsData->parameters);

		Helper\Analytics::getInstance($this->config->getUserId())->onTaskUpdate(
			event: $analyticsData->event->value,
			section: $analyticsData->section->value,
			element: $analyticsData->element?->value,
			subSection: $analyticsData->subSection?->value,
			params: $parameters,
		);
	}

	private function shouldSend(Analytics\Event $event, Task $taskBefore, Task $taskAfter): bool
	{
		return match ($event)
		{
			Analytics\Event::TaskComplete => $this->shouldSendTaskComplete($taskBefore, $taskAfter),
			default => true,
		};
	}

	private function shouldSendTaskComplete(Task $taskBefore, Task $taskAfter): bool
	{
		if (!in_array($taskAfter->status, [Task\Status::SupposedlyCompleted, Task\Status::Completed], true))
		{
			return false;
		}

		if ($taskBefore->status === $taskAfter->status)
		{
			return false;
		}

		if ($taskBefore->status === Task\Status::SupposedlyCompleted)
		{
			return false;
		}

		return true;
	}

	private function buildParameters(
		Analytics\Event $event,
		Task $task,
		?array $additionalParameters,
	): array
	{
		$parameters = [
			'p1' => 'taskId_' . $task->id,
		];

		$eventParameters = match ($event)
		{
			Analytics\Event::TaskComplete => $this->getTaskCompleteParameters($task),
			default => [],
		};

		return array_merge($parameters, $eventParameters, $additionalParameters ?? []);
	}

	private function getTaskCompleteParameters(Task $task): array
	{
		$createdDate = DateTime::createFromTimestamp($task->createdTs);
		$createdDate->setTimeZone(new DateTimeZone('UTC'));

		return [
			'p2' => $createdDate->format('Y-m-d\TH:i\Z'),
		];
	}
}
