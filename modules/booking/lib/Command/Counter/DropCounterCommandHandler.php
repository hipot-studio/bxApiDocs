<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Counter;

use Bitrix\Booking\Internals\Integration\Pull\PushEvent;
use Bitrix\Booking\Internals\Integration\Pull\PushService;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\CounterDictionary;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\PushPull\PushPullCommandType;
use Bitrix\Booking\Internals\Repository\CounterRepositoryInterface;

class DropCounterCommandHandler
{
	private CounterRepositoryInterface $counterRepository;

	public function __construct()
	{
		$this->counterRepository = Container::getCounterRepository();
	}

	public function __invoke(DropCounterCommand $command): void
	{
		match ($command->type)
		{
			CounterDictionary::BookingUnConfirmed,
			CounterDictionary::BookingDelayed,
			CounterDictionary::BookingNewYandexMaps => $this->handle($command),
			default => '',
		};
	}

	private function handle(DropCounterCommand $command): void
	{
		$affectedUserIds = $this->getAffectedUserIds($command);
		if (empty($affectedUserIds))
		{
			return;
		}

		$this->counterRepository->downMultiple(
			entityIds: [$command->entityId],
			types: [$command->type],
			userIds: $affectedUserIds,
		);

		foreach ($affectedUserIds as $userId)
		{
			$total = $this->counterRepository->get($userId, CounterDictionary::Total);
			\CUserCounter::Set(
				$userId,
				CounterDictionary::LeftMenu->value,
				$total,
				'**',
			);
		}

		(new PushService())->sendEvent(
			new PushEvent(
				command: PushPullCommandType::CountersUpdated->value,
				tag: PushPullCommandType::CountersUpdated->getTag(),
				params: [],
				entityId: $command->entityId,
			)
		);
	}

	private function getAffectedUserIds(DropCounterCommand $command): array
	{
		if ($command->userId)
		{
			return [$command->userId];
		}

		$result = [];

		$affectedUserIds = $this->counterRepository->getUserIdsByCounterType(
			entityIds: [$command->entityId],
			types: [$command->type],
		);
		foreach ($affectedUserIds as $affectedUserId)
		{
			$result[] = $affectedUserId;
		}

		return $result;
	}
}
