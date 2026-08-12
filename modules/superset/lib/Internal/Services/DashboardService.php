<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Superset\Internal\Api\Chart;
use Bitrix\Superset\Internal\Api\Dashboard;
use Bitrix\Superset\Internal\Api\Dataset;
use Bitrix\Superset\Internal\Api\Security\GuestToken;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Entities\OwnedEntityIds;
use Bitrix\Superset\Internal\Dto;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\Services\ImportExport\ArchiveRepacker;
use Bitrix\Superset\Internal\Services\ImportExport\EntitiesImporter;

final class DashboardService extends AbstractSupersetContext
{
	private const SUPERSET_USER_ID = 1;
	private const EXTENDED_STREAM_TIMEOUT = 90;

	public function get(int $dashboardId): Main\Result
	{
		$requestResult = $this->getDashboardApi()->getDashboardById($dashboardId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting dashboard info');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
		{
			return $this->createErrorResult('Invalid dashboard response');
		}

		$result = new Main\Result();
		$result->setData([
			'dashboard' => $this->prepareResultDashboard($decoded['result']),
		]);

		return $result;
	}

	public function list(array $ids = [], array $neqIds = []): Main\Result
	{
		$filter = [];
		if (!empty($ids))
		{
			$filter[] = [
				'col' => 'id',
				'opr' => 'in',
				'value' => [$ids],
			];
		}
		elseif (!empty($neqIds))
		{
			foreach ($neqIds as $neqId)
			{
				$filter[] = [
					'col' => 'id',
					'opr' => 'neq',
					'value' => (int)$neqId,
				];
			}
		}

		$preparedDashboards = [];
		$page = 0;
		$dashboardApi = $this->getDashboardApi();

		do
		{
			$requestResult = $dashboardApi->getDashboards($filter, $page, 100);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Getting dashboard list');
			}

			$decoded = $this->decode($requestResult->getAnswer());
			if (!is_array($decoded))
			{
				return $this->createErrorResult('Invalid dashboard list response');
			}

			foreach (($decoded['result'] ?? []) as $dashboard)
			{
				if (is_array($dashboard))
				{
					$preparedDashboards[] = $this->prepareResultDashboard($dashboard);
				}
			}

			$isRepeatRequest = count($decoded['result'] ?? []) === 100;
			$page++;
		}
		while ($isRepeatRequest);

		$result = new Main\Result();
		$result->setData([
			'dashboards' => $preparedDashboards,
			'common_count' => count($preparedDashboards),
		]);

		return $result;
	}

	public function getEmbedded(int $dashboardId): Main\Result
	{
		$dashboardApi = $this->getDashboardApi();
		$requestResult = $dashboardApi->getEmbeddedDashboardInfo($dashboardId);
		if ($requestResult->getHttpStatus() === HttpStatus::NOT_FOUND)
		{
			$embedResult = $dashboardApi->embedDashboard($dashboardId);
			if (!$embedResult->isSuccess() || $embedResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($embedResult, 'Enabling embedded dashboard');
			}

			$requestResult = $dashboardApi->getEmbeddedDashboardInfo($dashboardId);
		}

		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting embedded dashboard info');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
		{
			return $this->createErrorResult('Invalid embedded dashboard response');
		}

		$result = new Main\Result();
		$result->setData([
			'embedded' => $decoded['result'],
			'domain' => $this->server->getHost(),
		]);

		return $result;
	}

	public function getGuestToken(int $dashboardId, array $supersetUserData, array $rlsRules = [], int $expSeconds = 0): Main\Result
	{
		if (empty($supersetUserData['username']))
		{
			return $this->createErrorResult(
				'Superset user data must contain username',
				null,
				HttpStatus::BAD_REQUEST
			);
		}

		$requestFields = [
			'resources' => [
				[
					'id' => (string)$dashboardId,
					'type' => 'dashboard',
				],
			],
			'rls' => $rlsRules,
			'user' => [
				'username' => $supersetUserData['username'],
				'first_name' => $supersetUserData['first_name'] ?? '',
				'last_name' => $supersetUserData['last_name'] ?? '',
			],
		];

		if ($expSeconds > 0)
		{
			$requestFields['exp_seconds'] = $expSeconds;
		}

		$guestTokenResult = $this->getGuestTokenApi()->createGuestToken($requestFields);
		if ($guestTokenResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($guestTokenResult, 'Getting guest token');
		}

		$guestToken = $this->decode($guestTokenResult->getAnswer());
		$embeddedResult = $this->getEmbedded($dashboardId);
		if (!$embeddedResult->isSuccess())
		{
			return $embeddedResult;
		}

		$embedded = $embeddedResult->getData()['embedded'];

		$result = new Main\Result();
		$result->setData([
			'guest_token' => $guestToken['token'] ?? '',
			'uuid' => $embedded['uuid'] ?? '',
			'domain' => $this->server->getHost(),
		]);

		return $result;
	}

	public function update(int $dashboardId, array $fields): Main\Result
	{
		$fields = $this->verifyInnerDashboardFields($fields);
		if (empty($fields))
		{
			return $this->createErrorResult('Get empty field list to edit', null, HttpStatus::BAD_REQUEST);
		}

		$requestResult = $this->getDashboardApi()->updateDashboard($dashboardId, $fields);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Update dashboard fields');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dashboard update response');
		}

		$changedFields = [];
		if (is_array($decoded['result'] ?? null))
		{
			foreach ($decoded['result'] as $key => $value)
			{
				if (array_key_exists($key, $fields))
				{
					$changedFields[$key] = $value;
				}
			}
		}

		$result = new Main\Result();
		$result->setData([
			'changed_fields' => $changedFields,
		]);

		return $result;
	}

	public function replaceOwner(int $fromOwnerId, array $replacementOwnerIds, int $maxExecutionTime = 0): Main\Result
	{
		$requestResult = $this->getDashboardApi()->getDashboardsByOwnerId($fromOwnerId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting dashboards by owner');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dashboard owner replacement response');
		}

		$isUpdated = false;
		$timeStart = Main\Diag\Helper::getCurrentMicrotime();

		foreach (($decoded['result'] ?? []) as $dashboard)
		{
			if (!is_array($dashboard))
			{
				continue;
			}

			$ownerIds = array_map('intval', array_column($dashboard['owners'] ?? [], 'id'));
			if (!in_array($fromOwnerId, $ownerIds, true))
			{
				continue;
			}

			$isUpdated = true;
			if (count($ownerIds) > 1)
			{
				$key = array_search($fromOwnerId, $ownerIds, true);
				if ($key !== false)
				{
					unset($ownerIds[$key]);
				}
			}
			else
			{
				$ownerIds = $replacementOwnerIds;
			}

			$ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
			sort($ownerIds);

			$updateResult = $this->getDashboardApi()->setDashboardOwners((int)($dashboard['id'] ?? 0), $ownerIds);
			if ($updateResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($updateResult, 'Replacing dashboard owner');
			}

			if (
				$maxExecutionTime > 0
				&& (Main\Diag\Helper::getCurrentMicrotime() - $timeStart) > $maxExecutionTime
			)
			{
				break;
			}
		}

		$result = new Main\Result();
		$result->setData([
			'updated' => $isUpdated,
			'is_running' => $isUpdated,
		]);

		return $result;
	}

	public function delete(array $dashboardIds, bool $deleteRelatedEntities = false): Main\Result
	{
		$relatedEntities = null;
		if ($deleteRelatedEntities)
		{
			$relatedEntitiesResult = $this->getRelatedEntitiesForDeletion($dashboardIds);
			if (!$relatedEntitiesResult->isSuccess())
			{
				return $relatedEntitiesResult;
			}

			$relatedEntities = $relatedEntitiesResult->getData();
		}

		$requestResult = $this->getDashboardApi()->deleteDashboards($dashboardIds);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Deleting dashboard');
		}

		$cleanupErrors = [];
		if (is_array($relatedEntities))
		{
			$deletionResult = $this->deleteRelatedEntities($relatedEntities);
			if (!$deletionResult->isSuccess())
			{
				$cleanupErrors = $deletionResult->getErrorMessages();
			}
		}

		$result = new Main\Result();
		$result->setData([
			'ids' => array_values(array_map('intval', $dashboardIds)),
			'body' => $requestResult->getAnswer(),
			'cleanup_errors' => $cleanupErrors,
			'requestResult' => $requestResult,
		]);

		return $result;
	}

	public function copy(int $dashboardId, string $name, int $ownerId): Main\Result
	{
		$dashboardResult = $this->get($dashboardId);
		if (!$dashboardResult->isSuccess())
		{
			return $dashboardResult;
		}

		$dashboardData = $dashboardResult->getData()['dashboard'];
		$payload = [
			'css' => $dashboardData['css'] ?? '',
			'dashboard_title' => $name,
			'json_metadata' => $this->prepareJsonMetadata($dashboardData),
			'duplicate_slices' => false,
		];

		$dashboardApi = $this->getDashboardApi();
		$requestResult = $dashboardApi->copyDashboard($dashboardId, $payload);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Copying dashboard info');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$newDashboardId = (int)($decoded['result']['id'] ?? 0);
		if ($newDashboardId <= 0)
		{
			return $this->createErrorResult('Copied dashboard id was not returned');
		}

		$dashboardApi->embedDashboard($newDashboardId);
		if (!empty($dashboardData['published']))
		{
			$dashboardApi->publishDashboard($newDashboardId);
		}

		$dashboardApi->setDashboardOwners($newDashboardId, [$ownerId]);

		$newDashboardResult = $this->get($newDashboardId);
		if (!$newDashboardResult->isSuccess())
		{
			return $newDashboardResult;
		}

		$newDashboard = $newDashboardResult->getData()['dashboard'];
		$newDashboard['source_dashboard_title'] = $dashboardData['title'] ?? '';

		$result = new Main\Result();
		$result->setData([
			'dashboard' => $newDashboard,
		]);

		return $result;
	}

	public function import(
		array $uploadedFile,
		string $currency = '',
		string $langCode = '',
		?string $appCode = null,
		bool $requiresSubscription = false,
		bool $forceImportDatasets = false,
	): Main\Result
	{
		if (\CFile::CheckFile($uploadedFile, strExt: 'zip') !== '')
		{
			return $this->createErrorResult('File for import not found');
		}

		$connectionResult = $this->getDatabaseService()->getTrinoConnection();
		if (!$connectionResult->isSuccess())
		{
			return $connectionResult;
		}

		$connection = $connectionResult->getData()['connection'];
		$databaseDto = Dto\Convertor\Database\ArrayToDatabase::convert($connection);
		$databaseYaml = Dto\Convertor\Database\DatabaseToYaml::convert($databaseDto);

		$archiveRepacker = new ArchiveRepacker();
		$saveResult = $archiveRepacker->saveUploadedFile($uploadedFile);
		if (!$saveResult->isSuccess())
		{
			return $this->createErrorResult(
				'SaveAndGetFileFromPost: ' . implode("\n", $saveResult->getErrorMessages())
			);
		}

		$dashboardFileId = $saveResult->getData()['id'];
		$dashboardFile = $saveResult->getData()['file'];
		$entitiesImporter = new EntitiesImporter($this->server, $this->connector, $archiveRepacker);

		try
		{
			$importDatasetResult = $entitiesImporter->importDataset(
				$dashboardFile,
				$databaseDto->databaseName,
				$databaseYaml,
				$databaseDto->uuid,
				$currency,
				$langCode,
				$forceImportDatasets,
			);
			if (!$importDatasetResult->isSuccess())
			{
				return $this->createErrorResult(
					'Import dataset: ' . implode("\n", $importDatasetResult->getErrorMessages())
				);
			}

			$importChartResult = $entitiesImporter->importChart(
				$dashboardFile,
				$databaseDto->databaseName,
				$databaseYaml,
				$databaseDto->uuid,
				$currency,
				$langCode,
			);
			if (!$importChartResult->isSuccess())
			{
				return $this->createErrorResult(
					'Import chart: ' . implode("\n", $importChartResult->getErrorMessages())
				);
			}

			$dashboardUuid = $appCode ? self::generateDashboardUuidByAppCode($appCode) : null;
			$importDashboardResult = $entitiesImporter->importDashboard(
				$dashboardFile,
				$databaseDto->databaseName,
				$databaseYaml,
				$databaseDto->uuid,
				$dashboardUuid,
				$currency,
				$langCode,
				$requiresSubscription,
			);
		}
		finally
		{
			\CFile::Delete($dashboardFileId);
		}

		if (!$importDashboardResult->isSuccess())
		{
			return $this->createErrorResult(
				'Import dashboard: ' . implode("\n", $importDashboardResult->getErrorMessages())
			);
		}

		$chartUuids = $importChartResult->getData()['chartUuids'] ?? [];
		$usedLangId =
			$importDashboardResult->getData()['usedLangId']
			?? $importChartResult->getData()['usedLangId']
			?? $importDatasetResult->getData()['usedLangId']
			?? $langCode
		;

		$dashboards = [];
		$dashboardApi = $this->getDashboardApi();
		foreach (($importDashboardResult->getData()['repackData']['slugs'] ?? []) as $slug)
		{
			$dashboardBySlugResult = $dashboardApi->getDashboardBySlug((string)$slug);
			if (
				!$dashboardBySlugResult->isSuccess()
				|| $dashboardBySlugResult->getHttpStatus() !== HttpStatus::OK
			)
			{
				return $this->createRequestErrorResult($dashboardBySlugResult, 'DashboardImport. Get dashboard by slug');
			}

			$decoded = $this->decode($dashboardBySlugResult->getAnswer());
			if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
			{
				return $this->createErrorResult('DashboardImport. Invalid dashboard by slug response');
			}

			$dashboardData = $decoded['result'];
			$dashboardData['langCode'] = $usedLangId;
			$dashboardId = (int)($dashboardData['id'] ?? 0);
			$dashboards[] = $dashboardData;

			$metadata = $this->prepareImportMetadata($dashboardData, $chartUuids);
			$dashboardData['positions'] = $metadata['positions'] ?? [];
			$dashboardData['json_metadata'] = Json::encode($metadata);

			$updateResult = $dashboardApi->updateDashboard(
				$dashboardId,
				[
					'slug' => null,
					'published' => true,
					'json_metadata' => $this->prepareJsonMetadata($dashboardData),
				]
			);
			if (
				!$updateResult->isSuccess()
				|| $updateResult->getHttpStatus() !== HttpStatus::OK
			)
			{
				return $this->createRequestErrorResult($updateResult, 'DashboardImport. Update dashboard');
			}

			$embedResult = $dashboardApi->embedDashboard($dashboardId);
			if (
				!$embedResult->isSuccess()
				|| $embedResult->getHttpStatus() !== HttpStatus::OK
			)
			{
				return $this->createRequestErrorResult($embedResult, 'DashboardImport. Embed dashboard');
			}
		}

		$result = new Main\Result();
		$result->setData([
			'dashboards' => $dashboards,
			'usedLangId' => $usedLangId,
		]);

		return $result;
	}

	public function export(int $dashboardId, array $dashboardSettings = []): Main\Result
	{
		$requestResult = $this->getDashboardApi(self::EXTENDED_STREAM_TIMEOUT)->exportDashboard($dashboardId);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Dashboard export');
		}

		$repackResult = (new ArchiveRepacker())->repackDashboardForMarket(
			$requestResult->getAnswer(),
			$dashboardSettings
		);
		if (!$repackResult->isSuccess())
		{
			return $this->createErrorResult(implode("\n", $repackResult->getErrorMessages()));
		}

		$result = new Main\Result();
		$result->setData([
			'body' => $repackResult->getData()['content'] ?? '',
		]);

		return $result;
	}

	public function createEmpty(array $fields, int $ownerId): Main\Result
	{
		$jsonMetadata = '';
		if (!empty($fields['json_metadata']))
		{
			try
			{
				$jsonMetadata = Json::encode($fields['json_metadata']);
			}
			catch (Main\ArgumentException)
			{
				return $this->createErrorResult('Invalid json_metadata format', null, HttpStatus::BAD_REQUEST);
			}
		}

		$payload = [
			'certification_details' => '',
			'certified_by' => '',
			'css' => '',
			'dashboard_title' => (string)($fields['name'] ?? ''),
			'external_url' => '',
			'is_managed_externally' => false,
			'json_metadata' => $jsonMetadata,
			'owners' => [$ownerId],
			'position_json' => '',
			'published' => (bool)($fields['published'] ?? true),
			'roles' => [],
		];

		$requestResult = $this->getDashboardApi()->createDashboard($payload);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::CREATED
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Creating empty dashboard');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$newDashboardId = (int)($decoded['id'] ?? 0);
		if ($newDashboardId > 0)
		{
			$this->getDashboardApi()->embedDashboard($newDashboardId);
		}

		$result = new Main\Result();
		$result->setData([
			'body' => $decoded,
		]);

		return $result;
	}

	public function setOwner(int $dashboardId, int $ownerId): Main\Result
	{
		$dashboardApi = $this->getDashboardApi();

		$datasetsResult = $dashboardApi->getDashboardDatasets($dashboardId);
		if ($datasetsResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($datasetsResult, 'Changing dashboard owner. Get datasets');
		}

		$datasets = $this->decode($datasetsResult->getAnswer());
		$datasetApi = $this->getDatasetApi();
		foreach (($datasets['result'] ?? []) as $dataset)
		{
			if (!is_array($dataset))
			{
				continue;
			}

			$datasetId = (int)($dataset['id'] ?? 0);
			$datasetResult = $datasetApi->getDatasetById($datasetId);
			if ($datasetResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($datasetResult, 'Changing dashboard owner. Get dataset');
			}

			$decoded = $this->decode($datasetResult->getAnswer());
			$ownerIds = OwnedEntityIds::fromOwnersPayload($decoded['result']['owners'] ?? []);
			if ($ownerIds->isSystemOwned() || $ownerIds->contains($ownerId))
			{
				continue;
			}

			$updateResult = $datasetApi->updateDataset($datasetId, ['owners' => $ownerIds->withAdded($ownerId)->toArray()]);
			if ($updateResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($updateResult, 'Changing dashboard owner. Update dataset owners');
			}
		}

		$chartsResult = $dashboardApi->getDashboardCharts($dashboardId);
		if ($chartsResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($chartsResult, 'Changing dashboard owner. Get charts');
		}

		$charts = $this->decode($chartsResult->getAnswer());
		$chartApi = $this->getChartApi();
		foreach (($charts['result'] ?? []) as $chart)
		{
			if (!is_array($chart))
			{
				continue;
			}

			$chartId = (int)($chart['id'] ?? 0);
			$chartResult = $chartApi->getChartById($chartId);
			if ($chartResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($chartResult, 'Changing dashboard owner. Get chart');
			}

			$decoded = $this->decode($chartResult->getAnswer());
			$ownerIds = OwnedEntityIds::fromOwnersPayload($decoded['result']['owners'] ?? []);
			if ($ownerIds->isSystemOwned() || $ownerIds->contains($ownerId))
			{
				continue;
			}

			$updateResult = $chartApi->updateChart($chartId, ['owners' => $ownerIds->withAdded($ownerId)->toArray()]);
			if ($updateResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($updateResult, 'Changing dashboard owner. Update chart owners');
			}
		}

		$dashboardGetResult = $dashboardApi->getDashboardById($dashboardId);
		if ($dashboardGetResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($dashboardGetResult, 'Changing dashboard owner. Get dashboard');
		}

		$dashboardDecoded = $this->decode($dashboardGetResult->getAnswer());
		$dashboardOwnerIds = OwnedEntityIds::fromOwnersPayload($dashboardDecoded['result']['owners'] ?? []);
		if (!$dashboardOwnerIds->isSystemOwned() && !$dashboardOwnerIds->contains($ownerId))
		{
			$dashboardResult = $dashboardApi->setDashboardOwners(
				$dashboardId,
				$dashboardOwnerIds->withAdded($ownerId)->toArray(),
			);
			if (
				!$dashboardResult->isSuccess()
				|| $dashboardResult->getHttpStatus() !== HttpStatus::OK
			)
			{
				return $this->createRequestErrorResult($dashboardResult, 'Changing dashboard owner');
			}
		}

		$result = new Main\Result();
		$result->setData([
			'dashboard' => [
				'id' => $dashboardId,
			],
		]);

		return $result;
	}

	public function changeOwner(int $dashboardId, int $fromUserId, int $toUserId): Main\Result
	{
		$requestResult = $this->getDashboardApi()->getDashboardById($dashboardId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'changeOwnerAction');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$ownerIds = OwnedEntityIds::fromOwnersPayload($decoded['result']['owners'] ?? []);
		if ($ownerIds->isSystemOwned())
		{
			return $this->createErrorResult('cannot change the owner of a system dashboard', $requestResult);
		}

		$updateResult = $this->getDashboardApi()->setDashboardOwners(
			$dashboardId,
			$ownerIds->withReplaced($fromUserId, $toUserId)->toArray()
		);
		if (
			!$updateResult->isSuccess()
			|| $updateResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $this->createRequestErrorResult($updateResult, 'Changing dashboard owner');
		}

		$result = new Main\Result();
		$result->setData([
			'dashboard' => [
				'id' => $dashboardId,
			],
		]);

		return $result;
	}

	public function datasets(int $dashboardId): Main\Result
	{
		$requestResult = $this->getDashboardApi()->getDashboardDatasets($dashboardId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Get dashboard datasets');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dashboard datasets response');
		}

		$datasets = [];
		foreach (($decoded['result'] ?? []) as $dataset)
		{
			if (is_array($dataset) && isset($dataset['id']))
			{
				$datasets[(int)$dataset['id']] = $dataset['table_name'] ?? '';
			}
		}

		$result = new Main\Result();
		$result->setData([
			'datasets' => $datasets,
		]);

		return $result;
	}

	public function getReusedObjects(array $dashboardIds): Main\Result
	{
		$dashboardIds = array_values(array_map('intval', $dashboardIds));
		$requestResult = $this->getDashboardApi()->getDashboardReusedObjects($dashboardIds);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting dashboard entities usage');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !isset($decoded['result']) || !is_array($decoded['result']))
		{
			return $this->createErrorResult("Superset response no contain required 'result' field");
		}

		$data = $decoded['result'];
		$data['domain'] = $this->server->getHost();

		$result = new Main\Result();
		$result->setData($data);

		return $result;
	}

	/**
	 * @param int[] $chartIds
	 * @param array{by?: string, order?: string} $sort
	 */
	public function getOverview(
		int $dashboardId,
		array $appliedFilters = [],
		array $urlParams = [],
		int $streamTimeout = 60 * 5,
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
	): Main\Result
	{
		$requestResult = $this
			->getDashboardApi($streamTimeout)
			->getDashboardOverview($dashboardId, $appliedFilters, $urlParams, $chartIds, $sort, $limit, $offset)
		;
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting dashboard overview for AI');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dashboard overview response');
		}

		$result = new Main\Result();
		$result->setData($decoded['result'] ?? []);

		return $result;
	}

	public function getFilterValues(
		int $dashboardId,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
		int $streamTimeout = 60,
	): Main\Result
	{
		$requestResult = $this
			->getDashboardApi($streamTimeout)
			->getFilterValues($dashboardId, $filterColumn, $appliedFilters, $urlParams, $search, $limit)
		;
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting dashboard filter values for AI');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dashboard filter values response');
		}

		$result = new Main\Result();
		$result->setData($decoded['result'] ?? []);

		return $result;
	}

	private function prepareResultDashboard(array $supersetDashboard): array
	{
		$timestampModify = null;
		try
		{
			$timeChangedOn = $supersetDashboard['changed_on_utc'] ?? $supersetDashboard['changed_on'] ?? null;
			if ($timeChangedOn)
			{
				$dateModify = new \DateTime($timeChangedOn, new \DateTimeZone('UTC'));
				$timestampModify = DateTime::createFromPhp($dateModify)->getTimestamp();
			}
		}
		catch (\Exception)
		{
		}

		return [
			'id' => (int)($supersetDashboard['id'] ?? 0),
			'title' => $supersetDashboard['dashboard_title'] ?? '',
			'url' => $supersetDashboard['url'] ?? '',
			'edit_url' => $this->connector->buildRequestUrl($supersetDashboard['url'] ?? ''),
			'json_metadata' => $supersetDashboard['json_metadata'] ?? '',
			'is_editable' => (int)($supersetDashboard['created_by']['id'] ?? 0) !== self::SUPERSET_USER_ID,
			'css' => $supersetDashboard['css'] ?? '',
			'position_json' => $supersetDashboard['position_json'] ?? '',
			'published' => $supersetDashboard['published'] ?? true,
			'timestamp_modify' => $timestampModify,
		];
	}

	private function prepareJsonMetadata(array $dashboardData): string
	{
		$jsonMetadata = $this->decode((string)($dashboardData['json_metadata'] ?? ''));
		if (!is_array($jsonMetadata))
		{
			$jsonMetadata = [];
		}

		if (!empty($jsonMetadata['position_json']))
		{
			$jsonMetadata['positions'] = $this->decode($jsonMetadata['position_json']);
		}

		if (!empty($dashboardData['position_json']))
		{
			$jsonMetadata['positions'] = $this->decode($dashboardData['position_json']);
		}

		$jsonMetadata['filter_scopes'] = new \ArrayObject();
		if (empty($jsonMetadata['expanded_slices']))
		{
			$jsonMetadata['expanded_slices'] = new \ArrayObject();
		}
		if (empty($jsonMetadata['label_colors']))
		{
			$jsonMetadata['label_colors'] = new \ArrayObject();
		}
		if (empty($jsonMetadata['shared_label_colors']))
		{
			$jsonMetadata['shared_label_colors'] = new \ArrayObject();
		}
		if (empty($jsonMetadata['chart_configuration']))
		{
			$jsonMetadata['chart_configuration'] = new \ArrayObject();
		}
		if (!empty($jsonMetadata['positions'] ?? []))
		{
			foreach ($jsonMetadata['positions'] as $key => $position)
			{
				if (is_array($position) && is_array($position['meta'] ?? null) && empty($position['meta']))
				{
					$jsonMetadata['positions'][$key]['meta'] = new \ArrayObject();
				}
			}
		}

		if (!empty($jsonMetadata['native_filter_configuration'] ?? []))
		{
			foreach ($jsonMetadata['native_filter_configuration'] as $filterKey => $filter)
			{
				if (is_array($filter) && is_array($filter['targets'] ?? null))
				{
					foreach ($filter['targets'] as $targetKey => $target)
					{
						if (is_array($target) && empty($target))
						{
							$jsonMetadata['native_filter_configuration'][$filterKey]['targets'][$targetKey] = new \ArrayObject();
						}
					}
				}
			}
		}

		return Json::encode($jsonMetadata);
	}

	private function verifyInnerDashboardFields(array $fields): array
	{
		$resultFields = [];

		if (array_key_exists('dashboard_title', $fields))
		{
			$resultFields['dashboard_title'] = $fields['dashboard_title'];
		}

		if (array_key_exists('published', $fields))
		{
			$resultFields['published'] = $fields['published'];
		}

		return $resultFields;
	}

	private function getRelatedEntitiesForDeletion(array $dashboardIds): Main\Result
	{
		$entitiesUsageResult = $this->getReusedObjects($dashboardIds);
		if (!$entitiesUsageResult->isSuccess())
		{
			return $entitiesUsageResult;
		}

		$entitiesUsage = [
			'result' => $entitiesUsageResult->getData(),
		];
		if (!isset($entitiesUsage['result']['dashboards']) || !is_array($entitiesUsage['result']['dashboards']))
		{
			return $this->createErrorResult('Invalid entities usage response format');
		}

		$dashboardApi = $this->getDashboardApi();
		$chartsToDelete = [];
		$datasetsToDelete = [];

		foreach ($dashboardIds as $dashboardId)
		{
			$chartsResult = $dashboardApi->getDashboardCharts((int)$dashboardId);
			if ($chartsResult->getHttpStatus() === HttpStatus::OK)
			{
				$charts = $this->decode($chartsResult->getAnswer());
				foreach (($charts['result'] ?? []) as $chart)
				{
					if (!is_array($chart) || !isset($chart['id']))
					{
						continue;
					}

					$chartId = (int)$chart['id'];
					if (!$this->isEntityInUsageResponse($entitiesUsage, 'charts', $chartId))
					{
						$chartsToDelete[] = $chartId;
					}
				}
			}

			$datasetsResult = $dashboardApi->getDashboardDatasets((int)$dashboardId);
			if ($datasetsResult->getHttpStatus() === HttpStatus::OK)
			{
				$datasets = $this->decode($datasetsResult->getAnswer());
				foreach (($datasets['result'] ?? []) as $dataset)
				{
					if (!is_array($dataset) || !isset($dataset['id']))
					{
						continue;
					}

					$datasetId = (int)$dataset['id'];
					if (!$this->isEntityInUsageResponse($entitiesUsage, 'datasets', $datasetId))
					{
						$datasetsToDelete[] = $datasetId;
					}
				}
			}
		}

		$result = new Main\Result();
		$result->setData([
			'charts' => array_values(array_unique($chartsToDelete)),
			'datasets' => array_values(array_unique($datasetsToDelete)),
		]);

		return $result;
	}

	private function isEntityInUsageResponse(array $entitiesUsage, string $entityType, int $entityId): bool
	{
		foreach ($entitiesUsage['result']['dashboards'] as $dashboardData)
		{
			if ($entityType === 'charts' && isset($dashboardData['charts']))
			{
				foreach ($dashboardData['charts'] as $chart)
				{
					if ((int)($chart['id'] ?? 0) === $entityId)
					{
						return true;
					}
				}
			}

			if ($entityType === 'datasets' && isset($dashboardData['datasets']))
			{
				foreach ($dashboardData['datasets'] as $dataset)
				{
					if ((int)($dataset['id'] ?? 0) === $entityId)
					{
						return true;
					}
				}
			}
		}

		return false;
	}

	private function deleteRelatedEntities(array $relatedEntities): Main\Result
	{
		$result = new Main\Result();

		if (!empty($relatedEntities['charts']))
		{
			$deleteChartsResult = $this->getChartApi()->deleteCharts($relatedEntities['charts']);
			if ($deleteChartsResult->getHttpStatus() !== HttpStatus::OK)
			{
				$result->addError(new Main\Error(
					'Failed to delete charts: ' . $deleteChartsResult->getAnswer()
				));
			}
		}

		if (!empty($relatedEntities['datasets']))
		{
			$deleteDatasetsResult = $this->getDatasetApi()->deleteDatasets($relatedEntities['datasets']);
			if ($deleteDatasetsResult->getHttpStatus() !== HttpStatus::OK)
			{
				$result->addError(new Main\Error(
					'Failed to delete datasets: ' . $deleteDatasetsResult->getAnswer()
				));
			}
		}

		return $result;
	}

	private function prepareImportMetadata(array $dashboardData, array $chartUuids): array
	{
		$chartsToSave = [];
		$metadata = $this->decode((string)($dashboardData['json_metadata'] ?? '')) ?? [];
		$positions = $this->decode((string)($dashboardData['position_json'] ?? ''));
		if (!is_array($positions))
		{
			return $metadata;
		}

		$chartPositions = array_filter($positions, static function ($item) {
			return is_array($item) && ($item['type'] ?? null) === 'CHART';
		});
		foreach ($chartPositions as $chartPosition)
		{
			if (in_array($chartPosition['meta']['uuid'] ?? null, $chartUuids, true))
			{
				$chartsToSave[] = (int)($chartPosition['meta']['chartId'] ?? 0);
			}
		}

		foreach (($metadata['chart_configuration'] ?? []) as $chartId => $chartData)
		{
			if (in_array((int)$chartId, $chartsToSave, true))
			{
				$metadata['chart_configuration'][$chartId]['crossFilters']['chartsInScope'] = array_values(
					array_filter($chartsToSave, static fn($item) => (int)$item !== (int)$chartId)
				);
			}
			else
			{
				unset($metadata['chart_configuration'][$chartId]);
			}
		}

		$metadata['global_chart_configuration']['chartsInScope'] = $chartsToSave;
		$unsetPositionIds = [];

		foreach ($positions as $positionId => $positionData)
		{
			if (
				is_array($positionData)
				&& ($positionData['type'] ?? null) === 'CHART'
				&& !in_array((int)($positionData['meta']['chartId'] ?? 0), $chartsToSave, true)
			)
			{
				$unsetPositionIds[] = $positionId;
				unset($positions[$positionId]);
			}
		}

		foreach ($positions as $positionId => $positionData)
		{
			if (is_array($positionData) && ($positionData['type'] ?? null) !== 'CHART')
			{
				foreach (($positionData['children'] ?? []) as $positionChild)
				{
					if (in_array($positionChild, $unsetPositionIds, true))
					{
						unset($positions[$positionId]);
					}
				}
			}
		}

		foreach ($positions as $positionId => $positionData)
		{
			if (
				is_array($positionData)
				&& in_array($positionData['type'] ?? null, ['ROW', 'COLUMN'], true)
				&& ($positionData['children'] ?? []) === []
			)
			{
				unset($positions[$positionId]);
			}
		}
		$metadata['positions'] = $positions;

		return $metadata;
	}

	private static function generateDashboardUuidByAppCode(string $appCode): string
	{
		$hash = md5($appCode);

		return substr($hash, 0, 8) . '-'
			. substr($hash, 8, 4) . '-'
			. substr($hash, 12, 4) . '-'
			. substr($hash, 16, 4) . '-'
			. substr($hash, 20, 12)
		;
	}

	private function getDashboardApi(?int $streamTimeout = null): Dashboard
	{
		if ($streamTimeout !== null)
		{
			$connector = new SupersetInstance($this->server, [
				'streamTimeout' => $streamTimeout,
			]);

			return new Dashboard($connector);
		}

		return new Dashboard($this->connector);
	}

	private function getGuestTokenApi(): GuestToken
	{
		return new GuestToken($this->connector);
	}

	private function getChartApi(): Chart
	{
		return new Chart($this->connector);
	}

	private function getDatasetApi(): Dataset
	{
		return new Dataset($this->connector);
	}

	private function getDatabaseService(): DatabaseService
	{
		return new DatabaseService($this->server, $this->connector);
	}
}
