<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Journal\EventProcessor\Resource;

use Bitrix\Booking\Command\Resource\AddResourceCommand;
use Bitrix\Booking\Command\Resource\RemoveResourceCommand;
use Bitrix\Booking\Command\Resource\UpdateResourceCommand;
use Bitrix\Booking\Internals\Service\DelayedTask\Data\DelayedTaskDataMapper;
use Bitrix\Booking\Internals\Service\DelayedTask\DelayedTaskService;
use Bitrix\Booking\Internals\Service\DelayedTask\DelayedTaskType;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\EventProcessor;
use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalEventCollection;
use Bitrix\Booking\Internals\Service\Journal\JournalType;
use Bitrix\Booking\Internals\Service\ModuleOptions;
use Bitrix\Main\Event;
use Bitrix\Main\Update\Stepper;

class ResourceEventProcessor implements EventProcessor
{
	public function process(JournalEventCollection $eventCollection): void
	{
		/** @var JournalEvent $event */
		foreach ($eventCollection as $event)
		{
			match ($event->type)
			{
				JournalType::ResourceAdded => $this->processResourceAddedEvent($event),
				JournalType::ResourceUpdated => $this->processResourceUpdatedEvent($event),
				JournalType::ResourceDeleted => $this->processResourceDeletedEvent($event),
				default => '',
			};
		}
	}

	private function processResourceAddedEvent(JournalEvent $event): void
	{
		// event -> command
		$command = AddResourceCommand::mapFromArray($event->data);

		$this->addResourceCopies($command, $event);

		ModuleOptions::handleResourceAdded();

		$this->sendBitrixEvent(
			type: 'onResourceAdd',
			parameters: ['resource' => $command->resource],
		);
	}

	private function addResourceCopies(AddResourceCommand $command, JournalEvent $event): void
	{
		$copies = $command->getCopies();

		if ($copies && $copies > 0)
		{
			Stepper::bindClass(
				className: ResourceCopierStepper::class,
				moduleId: ResourceCopierStepper::MODULE,
				delay: 1,
				withArguments: [$event->id],
			);
		}
	}

	public function processResourceUpdatedEvent(JournalEvent $event): void
	{
		$command = UpdateResourceCommand::mapFromArray($event->data);

		$this->sendBitrixEvent(
			type: 'onResourceUpdate',
			parameters: ['resource' => $command->resource],
		);

		if ($resourceEntityChanges = $event->data['resourceEntityChanges'] ?? null)
		{
			(new DelayedTaskService())->create(
				(string)$event->entityId,
				DelayedTaskDataMapper::mapFromArray(
			DelayedTaskType::ResourceLinkedEntitiesChanged,
					[
						'resourceId' => $event->entityId,
						'deleted' => $resourceEntityChanges['deleted'] ?? null,
						'added' => $resourceEntityChanges['added'] ?? null,
					],
				)
			);
		}
	}

	public function processResourceDeletedEvent(JournalEvent $event): void
	{
		$command = RemoveResourceCommand::mapFromArray($event->data);

		$this->sendBitrixEvent(
			type: 'onResourceDelete',
			parameters: ['resourceId' => $command->id],
		);
	}

	private function sendBitrixEvent(string $type, array $parameters): void
	{
		(new Event(
			moduleId: 'booking',
			type: $type,
			parameters: $parameters,
		))->send();
	}
}
