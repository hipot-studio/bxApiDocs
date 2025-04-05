<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\External;

use \Bitrix\Main;
use \Bitrix\Baas;

class MigrateConsumptionLog extends BaseClientAction
{
	protected Baas\Repository\ConsumptionRepository $consumptionRepository;
	protected int $delay = 0;

	public function __construct(
		Baas\UseCase\External\Request\MigrateConsumptionLogRequest $request,
	)
	{
		parent::__construct($request);
		$this->consumptionRepository = $request->consumptionRepository;
		$this->delay = $this->client->getConfigs()->getMigrationDelay();
	}

	protected function run(): Main\Result
	{
		$timestamp = time();
		if (($timestamp - $this->client->getConfigs()->getMigrationLastSyncTime()) < $this->delay)
		{
			Baas\Internal\Diag\Logger::getInstance()->info('Migration delay is not expired', [
				'left' => $this->delay - ($timestamp - $this->client->getConfigs()->getMigrationLastSyncTime()),
			]);

			return new Main\Result();
		}

		$migrationMarker = 'm_' . $timestamp . '_' . rand(0, 1000);

		$logs = $this->consumptionRepository->collectLogForMigration($migrationMarker);

		if (!empty($logs))
		{
			Baas\Internal\Diag\Logger::getInstance()->info('Migration is in process', array_column($logs, 'ID'));

			$this->client->getConfigs()->setMigrationLastSyncTime($timestamp);
			try
			{
				$this
					->getSender()
					->performRequest('saveConsumptions', ['logs' => $logs])
				;
				$this->consumptionRepository->crossOutByMigrationMarker($migrationMarker);
				$this->client->getConfigs()->setMigrationDelay(0);
				Baas\Internal\Diag\Logger::getInstance()->info('Migration step has finished');
			}
			catch (\Throwable $e)
			{
				$this->consumptionRepository->resetMigrationMarker($migrationMarker);
				$this->client->getConfigs()->setMigrationDelay(15);
				Baas\Internal\Diag\Logger::getInstance()->warning('Migration step has failed', [
					'exception' => $e->getMessage(),
				]);

				throw $e;
			}
		}
		elseif ($this->consumptionRepository->hasLocalConsumptions() === false)
		{
			Baas\Internal\Diag\Logger::getInstance()->info('Migration has finished', $logs);
			$this->client->getConfigs()->setConsumptionsLogMigrated(true);
		}

		return new Main\Result();
	}
}
