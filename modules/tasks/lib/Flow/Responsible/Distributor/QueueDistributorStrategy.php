<?php

namespace Bitrix\Tasks\Flow\Responsible\Distributor;

use Bitrix\Tasks\Flow\Flow;
use Bitrix\Tasks\Flow\Migration\Strategy\Type\MigrateToManual\SwitchToManualDistributionAbsent;
use Bitrix\Tasks\Flow\Option\OptionDictionary;
use Bitrix\Tasks\Flow\Option\OptionService;
use Bitrix\Tasks\Flow\Responsible\ResponsibleQueue\ResponsibleQueueService;
use Bitrix\Tasks\Flow\Task\Status;

class QueueDistributorStrategy implements DistributorStrategyInterface
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
			$nextResponsibleId = ResponsibleQueueService::getInstance()->getNextResponsibleId($flow);

			if ($nextResponsibleId > 0)
			{
				$optionName = OptionDictionary::RESPONSIBLE_QUEUE_LATEST_ID->value;
				OptionService::getInstance()->save($flow->getId(), $optionName, (string)$nextResponsibleId);
			}
			else
			{
				$nextResponsibleId = $flow->getOwnerId();

				$migrationStrategy = new SwitchToManualDistributionAbsent();
				$migrationStrategy->migrate($flow->getId());
			}

			return $nextResponsibleId;
		}

		$responsibleId = $fields['RESPONSIBLE_ID'] ?? $taskData['RESPONSIBLE_ID'];

		return (int)$responsibleId;
	}
}