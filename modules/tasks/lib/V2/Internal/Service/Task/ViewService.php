<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\Forum\Task\UserTopic;
use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\Internals\Task\Event\View\OnTaskFirstViewEvent;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\Task\View;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\TimeLineService;
use Bitrix\Tasks\V2\Internal\Repository\ViewRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter;
use Bitrix\Tasks\V2\Internal\Service\EventService;
use Bitrix\Tasks\V2\Internal\Service\PushService;

class ViewService
{
	public function __construct(
		private readonly ViewRepositoryInterface $viewRepository,
		private readonly PushService $pushService,
		private readonly Counter\Service $counterService,
		private readonly TimeLineService $timeLineService,
		private readonly EventService $eventService,
	)
	{

	}

	public function set(View $view, bool $sendPush = true, bool $updateTopicLastVisit = false): void
	{
		$this->counterService->collect($view->taskId);

		$this->viewTask($view);

		if (!FormV2Feature::isOn())
		{
			$this->sendPush($view, $sendPush);
		}

		$this->updateTopic($view, $updateTopicLastVisit);

		$this->eventService->send('onTaskUpdateViewed', [
			'taskId' => $view->taskId,
			'userId' => $view->userId,
			'isRealView' => $view->isRealView,
		]);

		$this->counterService->send(new Counter\Command\AfterTaskView(
			taskId: $view->taskId,
			userId: $view->userId,
		));

		$this->timeLineService->viewComments($view->taskId, $view->userId);
	}

	private function sendPush(View $view, bool $sendPush): void
	{
		if (!$sendPush)
		{
			return;
		}

		$this->pushService->addEventByParameters(
			UserCollection::mapFromIds([$view->userId]),
			PushCommand::TASK_VIEWED,
			[
				'TASK_ID' => $view->taskId,
				'USER_ID' => $view->userId,
			]
		);
	}

	private function updateTopic(View $view, bool $updateTopicLastVisit): void
	{
		if (!$updateTopicLastVisit)
		{
			return;
		}

		UserTopic::updateLastVisit($view->taskId, $view->userId, DateTime::createFromTimestamp($view->viewedTs));
	}

	private function viewTask(View $view): void
	{
		$currentView = $this->viewRepository->get($view->taskId, $view->userId);

		$this->viewRepository->upsert($view);

		if ($view->isRealView && !$currentView?->isRealView)
		{
			$this->timeLineService->viewTask($view->taskId, $view->userId);

			$event = new OnTaskFirstViewEvent($view->userId, $view->taskId);

			$event->send();
		}
	}
}
