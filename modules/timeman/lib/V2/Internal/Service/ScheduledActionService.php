<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledAction;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledActionRegistrationResult;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledActionStatus;
use Bitrix\Timeman\V2\Internal\Repository\ScheduledActionRepository;

final class ScheduledActionService
{
	public function __construct(
		private readonly ScheduledActionRepository $scheduledActionRepository,
	)
	{
	}

	public function getById(int $id): ?ScheduledAction
	{
		return $this->scheduledActionRepository->getById($id);
	}

	public function findAction(string $type, int $userId, int $executeTime): ?ScheduledAction
	{
		return $this->scheduledActionRepository->findAction($type, $userId, $executeTime);
	}

	public function register(
		string $type,
		int $userId,
		int $executeTime,
		ScheduledActionStatus $status,
	): ScheduledActionRegistrationResult
	{
		$existingAction = $this->findAction($type, $userId, $executeTime);
		if ($existingAction !== null)
		{
			return new ScheduledActionRegistrationResult(
				actionId: $existingAction->id,
				created: false,
			);
		}

		$addResult = $this->scheduledActionRepository->add(
			type: $type,
			userId: $userId,
			executeTime: $executeTime,
			status: $status,
		);

		if ($addResult->isSuccess())
		{
			return new ScheduledActionRegistrationResult(
				actionId: (int)($addResult->getData()['id'] ?? 0),
				created: true,
			);
		}

		$existingAction = $this->findAction($type, $userId, $executeTime);
		if ($existingAction !== null)
		{
			return new ScheduledActionRegistrationResult(
				actionId: $existingAction->id,
				created: false,
			);
		}

		return new ScheduledActionRegistrationResult(
			actionId: 0,
			created: false,
		);
	}

	public function updateStatus(int $id, ScheduledActionStatus $status): void
	{
		if ($id <= 0)
		{
			return;
		}

		$this->scheduledActionRepository->updateStatus($id, $status);
	}

	public function complete(string $type, int $userId, int $executeTime): void
	{
		$action = $this->findAction($type, $userId, $executeTime);
		if ($action === null)
		{
			return;
		}

		$this->updateStatus($action->id, ScheduledActionStatus::Done);
	}
}
