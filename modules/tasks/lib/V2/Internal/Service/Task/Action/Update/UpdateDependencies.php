<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\Control\Dependence;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\Processor\Task\Scheduler;
use Bitrix\Tasks\Internals\Helper\Task;

class UpdateDependencies
{
	use ConfigTrait;

	public function __invoke(array $fields, array $fullTaskData): void
	{
		$this->savePrevious($fields, $fullTaskData);

		$this->attach($fields, $fullTaskData);

		$this->correctDatePlan($fields, $fullTaskData);
	}

	private function savePrevious(array $fields, array $fullTaskData): void
	{
		if (isset($fields['DEPENDS_ON']))
		{
			$dependence = new Dependence($this->config->getUserId(), $fullTaskData['ID']);
			$dependence->setPrevious($fields['DEPENDS_ON']);
		}
	}

	private function attach(array $fields, array $fullTaskData): void
	{
		// backward compatibility with PARENT_ID
		if (isset($fields['PARENT_ID']))
		{
			// PARENT_ID changed, reattach subtree from previous location to new one
			Task\Dependence::attach($fullTaskData['ID'], (int)$fields['PARENT_ID']);
		}
	}

	private function correctDatePlan(array $fields, array $fullTaskData): void
	{
		if ($this->config->isCorrectDatePlanDependent())
		{
			$shiftResult = $this->config->getRuntime()->getShiftResult();
			if (!$shiftResult)
			{
				$shiftResult = Scheduler::getInstance($this->config->getUserId())->processEntity($fullTaskData['ID'], $fields);
			}
			$saveResult = $shiftResult->save(['!ID' => $fullTaskData['ID']]);
			if ($saveResult->isSuccess())
			{
				$this->config->getRuntime()->setLegacyOperationResultData('SHIFT_RESULT', $shiftResult->exportData());
			}
		}
	}
}