<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Prepare;

use Bitrix\Tasks\Internals\Task\ParameterTable;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\Processor\Task\Scheduler;

class PrepareDatePlan implements PrepareFieldInterface
{
	use ConfigTrait;

	public function __invoke(array $fields, array $fullTaskData): array
	{
		if (!$this->config->isNeedCorrectDatePlan())
		{
			return $fields;
		}

		$parentChanged = $this->isParentChanged($fields, $fullTaskData);
		$datesChanged = $this->isDatesChanged($fields, $fullTaskData);
		$followDatesChanged = $this->isFollowDates($fields);

		$taskId = (int)$fullTaskData['ID'];

		if ($parentChanged)
		{
			// task was attached previously, and now it is being unattached or reattached to smth else
			// then we need to recalculate its previous parent...
			$scheduler = Scheduler::getInstance($this->config->getUserId());
			$shiftResultPrev = $scheduler->processEntity(
				$taskId,
				$fullTaskData,
				[
					'MODE' => 'BEFORE_DETACH',
				]
			);
			if ($shiftResultPrev->isSuccess())
			{
				$shiftResultPrev->save(['!ID' => $taskId]);
			}
		}
		else
		{
			if (array_key_exists('PARENT_ID', $fields))
			{
				unset($fields['PARENT_ID']);
			}
		}

		// when updating end or start date plan, we need to be sure the time is correct
		if (
			$parentChanged
			|| $datesChanged
			|| $followDatesChanged
		)
		{
			$scheduler = Scheduler::getInstance($this->config->getUserId());
			$shiftResult = $scheduler->processEntity(
				$taskId,
				$fields,
				[
					'MODE' => $parentChanged ? 'BEFORE_ATTACH' : '',
					'INHERIT_FOR' => [$taskId => $followDatesChanged],
				]
			);

			$this->config->getRuntime()->setShiftResult($shiftResult);
			if (!$shiftResult->isSuccess())
			{
				return $fields;
			}

			$shiftData = $shiftResult->getImpactById($taskId);
			if ($shiftData)
			{
				$startValue = $shiftData['START_DATE_PLAN'];
				$fields['START_DATE_PLAN'] = $startValue ?? 0;

				$endValue = $shiftData['END_DATE_PLAN'];
				$fields['END_DATE_PLAN'] = $endValue ?? 0;

				$fields['DURATION_PLAN_SECONDS'] = $shiftData['DURATION_PLAN_SECONDS'];

				$this->config->getRuntime()->setLegacyOperationResultData('SHIFT_RESULT', $shiftResult->getData());
			}
		}

		if (
			isset($fields['END_DATE_PLAN'])
			&& empty($fields['END_DATE_PLAN'])
		)
		{
			$fields['DURATION_PLAN'] = 0;
		}

		$pipeline = new PreparePipeline($this->config, [
			PrepareDurationPlan::class,
		]);

		return $pipeline($fields, $fullTaskData);
	}
	
	private function isParentChanged(array $fields, array $fullTaskData): bool
	{
		if (!isset($fields['PARENT_ID']))
		{
			return false;
		}

		$fields['PARENT_ID'] = (int)$fields['PARENT_ID'];
		$fullTaskData['PARENT_ID'] = (int)($fullTaskData['PARENT_ID'] ?? 0);

		return $fields['PARENT_ID'] !== $fullTaskData['PARENT_ID'];
	}

	private function isDatesChanged(array $fields, array $fullTaskData): bool
	{
		if (
			isset($fields['START_DATE_PLAN'])
			&& (string)$fullTaskData['START_DATE_PLAN'] !== (string)$fields['START_DATE_PLAN']
		)
		{
			return true;
		}

		if (
			isset($fields['END_DATE_PLAN'])
			&& (string)$fullTaskData['END_DATE_PLAN'] !== (string)$fields['END_DATE_PLAN']
		)
		{
			return true;
		}
		
		return false;
	}

	private function isFollowDates(array $fields): bool
	{
		if (!is_array($fields['SE_PARAMETER'] ?? null))
		{
			return false;
		}

		foreach ($fields['SE_PARAMETER'] as $parameter)
		{
			if (
				is_array($parameter)
				&& (int)$parameter['CODE'] === ParameterTable::PARAM_SUBTASKS_TIME
				&& $parameter['VALUE'] === 'Y'
			)
			{
				return true;
			}
		}

		return false;
	}
}
