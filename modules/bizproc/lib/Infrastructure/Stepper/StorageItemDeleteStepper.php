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

		$filter = Json::decode($filterJson) ?? [];

		if ($storageTypeId <= 0)
		{
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
			return self::FINISH_EXECUTION;
		}

		try
		{
			$command = new DeleteStorageItemCommand($ids);
			$command->run();
		}
		catch (\Throwable $e)
		{}

		$this->setOuterParams([$storageTypeId, $filterJson]);

		return self::CONTINUE_EXECUTION;
	}

	public static function bindStorage(int $storageTypeId, array $filter = []): void
	{
		$filterJson = Json::encode($filter);
		static::bind(0, [$storageTypeId, $filterJson]);
	}
}