<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;

use Bitrix\Main\Application;
use Bitrix\Tasks\V2\Internal\ConfigurationDelegate;
use Bitrix\Tasks\V2\Psr\EventDispatcher\EventDispatcherInterface;

class BackgroundEventDispatcher implements EventDispatcherInterface
{
	private readonly int $defaultEventPriority;


	/** @param array<int, object[]> */
	private array $events = [];

	public function __construct(
		private readonly Application $application,
		private readonly EventDispatcher $eventDispatcher,
		ConfigurationDelegate $configuration,
	)
	{
		$config = $configuration->get('event_dispatcher');
		$this->defaultEventPriority = (int)($config['background_priority'] ?? Application::JOB_PRIORITY_NORMAL);
	}

	public function __invoke(int $priority): void
	{
		$events = $this->events[$priority] ?? [];
		unset($this->events[$priority]);

		foreach ($events as $event)
		{
			$this->eventDispatcher->dispatch($event);
		}
	}

	public function dispatch(object $event, ?int $priority = null)
	{
		$priority = $priority ?? $this->getEventPriority($event);

		if (!array_key_exists($priority, $this->events))
		{
			$this->application->addBackgroundJob(job: $this, args: [$priority], priority: $priority);
		}

		$this->events[$priority][] = $event;

		return $event;
	}

	public function getEventPriority(object $event): int
	{
		return match (method_exists($event, 'getBackgroundPriority')) {
			true => $event->getBackgroundPriority(),
			default => $this->defaultEventPriority,
		};
	}
}
