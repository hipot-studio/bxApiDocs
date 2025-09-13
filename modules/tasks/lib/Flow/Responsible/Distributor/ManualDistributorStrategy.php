<?php

namespace Bitrix\Tasks\Flow\Responsible\Distributor;

use Bitrix\Tasks\Flow\Flow;
use Bitrix\Tasks\Flow\Migration\Strategy\Type\MigrateToManual\ForceManualDistributorAbsentChange;
use Bitrix\Tasks\Flow\Option\OptionDictionary;
use Bitrix\Tasks\Flow\Option\OptionService;
use Bitrix\Tasks\Flow\Task\Status;
use Bitrix\Tasks\Util\User;

class ManualDistributorStrategy implements DistributorStrategyInterface
{
	public function distribute(Flow $flow, array $fields, array $taskData): int
	{
		$isTaskAddedToFlow = false;
		if (isset($fields['FLOW_ID']) && (int)$fields['FLOW_ID'] > 0)
		{
			$isTaskAddedToFlow =
				!isset($taskData['FLOW_ID'])
				|| (int)$taskData['FLOW_ID'] !== (int)$fields['FLOW_ID']
			;
		}

		$isTaskStatusNew =
			isset($taskData['REAL_STATUS'])
			&& in_array($taskData['REAL_STATUS'], [Status::NEW, Status::PENDING])
		;

		if (empty($taskData) || ($isTaskAddedToFlow && $isTaskStatusNew))
		{
			$distributorOption =
				OptionService::getInstance()
					->getOption($flow->getId(), OptionDictionary::MANUAL_DISTRIBUTOR_ID->value)
			;

			$manualDistributorId = $distributorOption?->getValue();
			if (is_null($manualDistributorId) || $manualDistributorId <= 0 || $this->isUserAbsent($manualDistributorId))
			{
				$manualDistributorId = $flow->getOwnerId();

				$migrationStrategy = new ForceManualDistributorAbsentChange();
				$migrationStrategy->migrate($flow->getId());
			}

			return $manualDistributorId;
		}

		$responsibleId = $fields['RESPONSIBLE_ID'] ?? $taskData['RESPONSIBLE_ID'];

		return (int)$responsibleId;
	}

	private function isUserAbsent(int $userId): bool
	{
		return !empty(User::isAbsence([$userId]));
	}
}