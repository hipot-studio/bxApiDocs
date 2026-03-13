<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Stepper;

use Bitrix\Bizproc\Public\Command\StorageItem\DeleteStorageItemCommand;
use Bitrix\Main;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Main\Web\Json;

class StorageItemDeleteStepper extends Main\Update\Stepper
{
	protected static $moduleId = 'bizproc';

	private const STEP_ROWS_LIMIT = 100;

	public function execute(array &$option)
	{
		$outerParams = $this->getOuterParams();
		$storageTypeId = (int)($outerParams[0] ?? 0);
		$filterJson = (string)($outerParams[1] ?? '');
		$workflowId = (string)($outerParams[2] ?? '');
		$activityName = (string)($outerParams[3] ?? '');

		try
		{
			$filter = Json::decode($filterJson) ?? [];
		}
		catch (\Throwable $e)
		{
			$this->notifyWorkflow($workflowId, $activityName);

			return self::FINISH_EXECUTION;
		}

		if ($storageTypeId <= 0)
		{
			$this->notifyWorkflow($workflowId, $activityName);

			return self::FINISH_EXECUTION;
		}

		$provider = new StorageItemProvider($storageTypeId);

		$ids = $provider->getItems([
			'filter' => $filter,
			'select' => ['ID'],
			'order' => ['ID' => 'ASC'],
			'limit' => self::STEP_ROWS_LIMIT,
		])?->getEntityIds();

		if (empty($ids))
		{
			$this->notifyWorkflow($workflowId, $activityName);

			return self::FINISH_EXECUTION;
		}

		try
		{
			$command = new DeleteStorageItemCommand($ids);
			$command->run();
		}
		catch (\Throwable $e)
		{
			$this->notifyWorkflow($workflowId, $activityName);

			return self::FINISH_EXECUTION;
		}

		$this->setOuterParams([$storageTypeId, $filterJson, $workflowId, $activityName]);

		return self::CONTINUE_EXECUTION;
	}

	private function notifyWorkflow(string $workflowId, string $activityName): void
	{
		if ($workflowId && $activityName)
		{
			\CBPSchedulerService::retrySendEventToWorkflow($workflowId, $activityName);
		}
	}

	public static function bindStorage(
		int $storageTypeId,
		array $filter = [],
		string $workflowId = '',
		string $activityName = ''
	): void
	{
		$filterJson = Json::encode($filter);
		static::bind(0, [$storageTypeId, $filterJson, $workflowId, $activityName]);
	}
}
