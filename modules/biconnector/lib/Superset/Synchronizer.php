<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\Main\Config\Option;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;

class Synchronizer
{
	private const INIT_REQUIRED_DATASET = '~superset_init_required_dataset_done_v_1';
	private const INIT_REQUIRED_DATASET_TIME = '~superset_init_required_dataset_last_attempt';

	public function initRequiredDataset(): void
	{
		if (self::isRequiredDatasetInited())
		{
			return;
		}

		if (!self::canInitRequiredDataset())
		{
			return;
		}

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return;
		}

		$integrator = Integrator::getInstance();
		$response = $integrator->initRequiredDataset();
		if ($response->hasErrors())
		{
			$loggerFields = [
				'message' => "Synchronizer::initRequiredDataset",
			];
			Logger::logErrors($response->getErrors(), $loggerFields);

			return;
		}

		self::setRequiredDatasetInited();
	}

	private static function isRequiredDatasetInited(): bool
	{
		return Option::get('biconnector', self::INIT_REQUIRED_DATASET, 'N') === 'Y';
	}

	private static function setRequiredDatasetInited(): void
	{
		Option::set('biconnector', self::INIT_REQUIRED_DATASET, 'Y');
	}

	private static function canInitRequiredDataset(): bool
	{
		$lastAttempt = (int)Option::get('biconnector', self::INIT_REQUIRED_DATASET_TIME, 0);
		$now = time();

		$canInit = ($now - $lastAttempt) > 3600; // 1 hour

		if ($canInit)
		{
			Option::set('biconnector', self::INIT_REQUIRED_DATASET_TIME, $now);
		}

		return $canInit;
	}
}
