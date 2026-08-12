<?php

namespace Bitrix\Superset\Internal\Services\ImportExport;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\HttpStatus;

final class EntitiesImporter
{
	private Server $server;
	private SupersetInstance $connector;
	private ArchiveRepacker $archiveRepacker;

	public function __construct(
		Server $server,
		?SupersetInstance $connector = null,
		?ArchiveRepacker $archiveRepacker = null,
	)
	{
		$this->server = $server;
		$this->connector = $connector ?? new SupersetInstance($server);
		$this->archiveRepacker = $archiveRepacker ?? new ArchiveRepacker();
	}

	public function importDataset(
		array $datasetFile,
		string $databaseName,
		string $databaseContent,
		string $databaseUuid,
		string $currency,
		string $langCode = '',
		bool $forceImport = false,
	): Result
	{
		$result = new Result();
		$langCode = $this->resolveLangCode($langCode);

		$datasetApi = new Api\Dataset($this->connector);
		$repackDatasetResult = $this->archiveRepacker->repackDataset(
			$datasetFile,
			$databaseName,
			$databaseContent,
			$databaseUuid,
			$datasetApi,
			$langCode,
			$currency,
			$forceImport,
		);

		if ($repackDatasetResult->isSuccess())
		{
			if ($repackDatasetResult->getData()['noDatasets'] ?? false)
			{
				return $result->setData([
					'importAnswer' => 'All datasets are in actual state. No datasets were imported.',
					'usedLangId' => $repackDatasetResult->getData()['usedLangId'] ?? $langCode,
				]);
			}

			$repackDatasetData = $repackDatasetResult->getData();
			$newFilePath = $repackDatasetData['newFilePath'];

			$datasetApiResult = $datasetApi->importDataset($newFilePath);
			if (
				$datasetApiResult->isSuccess()
				&& $datasetApiResult->getHttpStatus() === HttpStatus::OK
			)
			{
				$result->setData([
					'importAnswer' => $datasetApiResult->getAnswer(),
					'usedLangId' => $repackDatasetResult->getData()['usedLangId'] ?? $langCode,
				]);
			}
			else
			{
				$result->addError(new Error($datasetApiResult->getAnswer()));
			}
		}
		else
		{
			$result->addErrors($repackDatasetResult->getErrors());
		}

		return $result;
	}

	public function importChart(
		array $chartFile,
		string $databaseName,
		string $databaseContent,
		string $databaseUuid,
		string $currency,
		string $langCode = '',
	): Result
	{
		$result = new Result();
		$langCode = $this->resolveLangCode($langCode);

		$repackChartResult = $this->archiveRepacker->repackChart(
			$chartFile,
			$databaseName,
			$databaseContent,
			$databaseUuid,
			$langCode,
			$currency,
		);
		if ($repackChartResult->isSuccess())
		{
			$repackChartData = $repackChartResult->getData();
			$newFilePath = $repackChartData['newFilePath'];

			$importChartApi = new Api\Chart($this->connector);
			$chartApiResult = $importChartApi->importChart($newFilePath);
			if (
				$chartApiResult->isSuccess()
				&& $chartApiResult->getHttpStatus() === HttpStatus::OK
			)
			{
				$result->setData([
					'importAnswer' => $chartApiResult->getAnswer(),
					'chartUuids' => $repackChartData['chartUuids'],
					'usedLangId' => $repackChartResult->getData()['usedLangId'] ?? $langCode,
				]);
			}
			else
			{
				$result->addError(new Error($chartApiResult->getAnswer()));
			}
		}
		else
		{
			$result->addErrors($repackChartResult->getErrors());
		}

		return $result;
	}

	public function importDashboard(
		array $dashboardFile,
		string $databaseName,
		string $databaseContent,
		string $databaseUuid,
		?string $dashboardUuid,
		string $currency,
		string $langCode = '',
		bool $requiresSubscription = false,
	): Result
	{
		$result = new Result();
		$langCode = $this->resolveLangCode($langCode);

		$repackDashboardResult = $this->archiveRepacker->repackDashboard(
			$dashboardFile,
			$databaseName,
			$databaseContent,
			$databaseUuid,
			$dashboardUuid,
			$langCode,
			$currency,
		);
		if ($repackDashboardResult->isSuccess())
		{
			$repackDashboardData = $repackDashboardResult->getData();
			$newFilePath = $repackDashboardData['newFilePath'];

			$importDashboardApi = new Api\Dashboard($this->connector);
			$dashboardApiResult = $importDashboardApi->importDashboard(
				$newFilePath,
				[
					'requiresSubscription' => $requiresSubscription,
				]
			);
			if (
				$dashboardApiResult->isSuccess()
				&& $dashboardApiResult->getHttpStatus() === HttpStatus::OK
			)
			{
				$result->setData([
					'repackData' => $repackDashboardData,
					'importAnswer' => $dashboardApiResult->getAnswer(),
					'usedLangId' => $repackDashboardResult->getData()['usedLangId'] ?? $langCode,
				]);
			}
			else
			{
				$result->addError(new Error($dashboardApiResult->getAnswer()));
			}
		}
		else
		{
			$result->addErrors($repackDashboardResult->getErrors());
		}

		return $result;
	}

	private function resolveLangCode(string $langCode): string
	{
		if ($langCode !== '')
		{
			return $langCode;
		}

		$account = $this->server->getAccount();
		if (is_object($account) && method_exists($account, 'getRegion'))
		{
			$accountLang = (string)$account->getRegion();
			if ($accountLang !== '')
			{
				return $accountLang;
			}
		}

		if (\defined('LANGUAGE_ID') && is_string(LANGUAGE_ID) && LANGUAGE_ID !== '')
		{
			return LANGUAGE_ID;
		}

		return 'en';
	}
}
