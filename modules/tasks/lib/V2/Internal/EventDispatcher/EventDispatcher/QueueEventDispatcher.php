<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;

use Bitrix\Main\Application;
use Bitrix\Tasks\V2\Internal\Async\QueueId;
use Bitrix\Tasks\V2\Internal\ConfigurationDelegate;
use Bitrix\Tasks\V2\Internal\EventDispatcher\Async\Message;
use Bitrix\Tasks\V2\Psr\EventDispatcher\EventDispatcherInterface;

class QueueEventDispatcher implements EventDispatcherInterface
{
	private readonly QueueId $defaultQueueId;

	/** @param array<string, object[]> */
	private array $events = [];

	private readonly int $backgroundJobPriority;

	public function __construct(
		private readonly Application $application,
		ConfigurationDelegate $configuration,
	)
	{
		$config = $configuration->get('event_dispatcher');
		$this->defaultQueueId = QueueId::tryFrom($config['queue_id'] ?? null) ?? QueueId::EventDispatcher;
		$this->backgroundJobPriority = (int)($config['background_priority'] ?? Application::JOB_PRIORITY_NORMAL);
	}

	public function __invoke(): void
	{
		$events = $this->events;
		$this->events = [];

		foreach ($events as $queueId => $events)
		{
			$message = new Message($events);
			$message->send($queueId);
		}
	}

	public function dispatch(object $event, QueueId|string|null $queueId = null)
	{
		if (is_string($queueId))
		{
			$queueId = QueueId::tryFrom($queueId);
		}

		$queueId = $queueId ?? $this->getQueueId($event);

		if (!empty($this->events))
		{
			$this->application->addBackgroundJob(job: $this, priority: $this->backgroundJobPriority);
		}

		$this->events[$queueId->value][] = $event;

		return $event;
	}

	private function getQueueId(object $event): QueueId
	{
		return match (method_exists($event, 'getQueueId')) {
			true => $event->getQueueId(),
			default => $this->defaultQueueId,
		};
	}
}
