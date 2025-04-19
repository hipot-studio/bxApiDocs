<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Event;

use Bitrix\Main\EventResult;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Onboarding\Internal\Factory\CommandModelFactory;
use Bitrix\Tasks\Onboarding\Internal\Type;
use Bitrix\Tasks\Onboarding\OnboardingFeature;
use Bitrix\Tasks\Onboarding\Transfer\CommandModelCollection;

final class AddTaskEventListener extends AbstractEventListener
{
	public function onTaskAdd(int $taskId, array $fields): EventResult
	{
		$eventResult = new EventResult(EventResult::SUCCESS);

		$createdBy = (int)$fields['CREATED_BY'];
		$responsibleId = (int)$fields['RESPONSIBLE_ID'];

		if ($this->isOnePersonTask($fields))
		{
			return $eventResult;
		}

		$status = (int)($fields['STATUS'] ?? 0);
		if ($status !== Status::PENDING)
		{
			return $eventResult;
		}

		if ($this->isInvitedUser($responsibleId))
		{
			$this->addCommandsForInvitedResponsible($taskId, $responsibleId);
		}
		elseif (OnboardingFeature::isNewPortal())
		{
			$this->addCommandsForExistingResponsible($taskId, $responsibleId, $createdBy);
		}

		return $eventResult;
	}

	private function addCommandsForInvitedResponsible(int $taskId, int $responsibleId): void
	{
		$commandModels = new CommandModelCollection(
			CommandModelFactory::create(Type::ResponsibleInvitationNotAcceptedOneDay, $taskId, $responsibleId),
		);

		$this->saveCommandModels($commandModels);
	}

	private function addCommandsForExistingResponsible(int $taskId, int $responsibleId, int $createdBy): void
	{
		$commandModels = new CommandModelCollection(
			CommandModelFactory::create(Type::OneDayNotViewed, $taskId, $responsibleId, true),
			CommandModelFactory::create(Type::TwoDaysNotViewed, $taskId, $createdBy, true)
		);

		$this->saveCommandModels($commandModels);
	}
}