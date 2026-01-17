<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Counter;

use Bitrix\Booking\Internals\Integration\Pull\PushEvent;
use Bitrix\Booking\Internals\Integration\Pull\PushService;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\CounterDictionary;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\PushPull\PushPullCommandType;
use Bitrix\Booking\Internals\Repository\CounterRepositoryInterface;

class UpCounterCommandHandler
{
	private CounterRepositoryInterface $counterRepository;

	public function __construct()
	{
		$this->counterRepository = Container::getCounterRepository();
	}

	public function __invoke(UpCounterCommand $command): void
	{
		match ($command->type)
		{
			CounterDictionary::BookingUnConfirmed,
			CounterDictionary::BookingDelayed,
			CounterDictionary::BookingNewYandexMaps => $this->handle($command),
			default => '',
		};
	}

	private function handle(UpCounterCommand $command): void
	{
		$userId = $command->userId;
		if (!$userId)
		{
			return;
		}

		$this->counterRepository->up(
			entityId: $command->entityId,
			type: $command->type,
			userId: $userId,
		);

		\CUserCounter::Set(
			$userId,
			CounterDictionary::LeftMenu->value,
			$this->counterRepository->get($userId, CounterDictionary::Total),
			'**',
		);

		(new PushService())->sendEvent(
			new PushEvent(
				command: PushPullCommandType::CountersUpdated->value,
				tag: PushPullCommandType::CountersUpdated->getTag(),
				params: [],
				entityId: $command->entityId,
			)
		);
	}
}
