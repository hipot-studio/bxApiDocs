<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\Control\Dependence;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\Processor\Task\Result;
use Bitrix\Tasks\Processor\Task\Scheduler;
use CTasks;

class AddDependencies
{
	use ConfigTrait;

	public function __invoke(array $fields): void
	{
		$taskId = $fields['ID'];

		if (array_key_exists('DEPENDS_ON', $fields))
		{
			$dependence = new Dependence($this->config->getUserId(), $taskId);
			$dependence->addPrevious($fields['DEPENDS_ON']);
		}

		$parentId = 0;
		if (array_key_exists('PARENT_ID', $fields))
		{
			$parentId = (int)$fields['PARENT_ID'];
		}

		// backward compatibility with PARENT_ID
		if ($parentId)
		{
			\Bitrix\Tasks\Internals\Helper\Task\Dependence::attachNew($taskId, $parentId);
		}

		$shiftResult = $this->config->getRuntime()->getShiftResult();
		if (!$shiftResult)
		{
			return;
		}

		if ($parentId)
		{
			$childrenCountDbResult = CTasks::GetChildrenCount([], $parentId);
			$fetchedChildrenCount = $childrenCountDbResult->Fetch();
			$childrenCount = (int)($fetchedChildrenCount ? $fetchedChildrenCount['CNT'] : 0);

			if ($childrenCount === 1)
			{
				$scheduler = Scheduler::getInstance($this->config->getUserId());
				$shiftResult = $scheduler->processEntity(
					0,
					$fields,
					['MODE' => 'BEFORE_ATTACH'],
				);
			}
		}

		$shiftResult->save(['!ID' => 0]);
	}
}
