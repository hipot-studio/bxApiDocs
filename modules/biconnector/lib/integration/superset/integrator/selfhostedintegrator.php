<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorEventLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SelfHostedConnectionService;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Superset\Public\Dto\ArchiveFileDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Dto\SupersetUserRuntimeDto;
use Bitrix\Superset\Public\Commands\Cache\InvalidateSupersetCacheCommand;
use Bitrix\Superset\Public\Commands\Dashboard\CopyDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\CreateEmptyDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\DeleteDashboardsCommand;
use Bitrix\Superset\Public\Commands\Dashboard\ExportDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\ImportDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\SetDashboardOwnerCommand;
use Bitrix\Superset\Public\Commands\Dashboard\UpdateDashboardCommand;
use Bitrix\Superset\Public\Commands\Database\ChangeDatabaseTokenCommand;
use Bitrix\Superset\Public\Commands\Dataset\CreateDatasetCommand;
use Bitrix\Superset\Public\Commands\Dataset\DeleteDatasetCommand;
use Bitrix\Superset\Public\Commands\Dataset\InitRequiredDatasetCommand;
use Bitrix\Superset\Public\Commands\Server\SetServerTimezoneCommand;
use Bitrix\Superset\Public\Commands\Dataset\UpdateDatasetCommand;
use Bitrix\Superset\Public\Commands\Subscription\SetSubscriptionExpirationCommand;
use Bitrix\Superset\Public\Commands\Subscription\SyncSubscriptionRequireCommand;
use Bitrix\Superset\Public\Commands\UnusedElements\DeleteUnusedElementsCommand;
use Bitrix\Superset\Public\Commands\User\ActivateSupersetUserCommand;
use Bitrix\Superset\Public\Commands\User\CreateSupersetUserCommand;
use Bitrix\Superset\Public\Commands\User\DeactivateSupersetUserCommand;
use Bitrix\Superset\Public\Commands\User\SetEmptySupersetUserRoleCommand;
use Bitrix\Superset\Public\Commands\User\SyncSupersetUserProfileCommand;
use Bitrix\Superset\Public\Commands\User\UpdateSupersetUserCommand;
use Bitrix\Superset\Public\Providers\ChartProvider;
use Bitrix\Superset\Public\Providers\DashboardProvider;
use Bitrix\Superset\Public\Providers\DatasetProvider;
use Bitrix\Superset\Public\Providers\UnusedElementsProvider;
use Bitrix\Superset\Public\Providers\UserProvider;

/**
 * Integrator implementation for self-hosted Superset.
 * Delegates Superset API calls to the local boxed module instead of the cloud proxy.
 *
 * Infrastructure methods (register, freeze, etc.) are no-op since the client manages the instance.
 */
class SelfHostedIntegrator implements IntegratorInterface
{
	static private self $instance;

	private IntegratorLogger $logger;
	private SelfHostedConnectionService $connectionService;
	private ?ServerReferenceDto $server = null;

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
		$this->logger = self::getDefaultLogger();
		$this->connectionService = new SelfHostedConnectionService();
		$this->server = $this->getServer();
	}

	private static function getDefaultLogger(): IntegratorLogger
	{
		return new IntegratorEventLogger();
	}

	private function createDefaultRequest(string $action): IntegratorRequest
	{
		return (new IntegratorRequest())
			->setAction($action)
			->addBefore(new Middleware\TariffRestriction())
			->addBefore(new Middleware\UserAccess())
			->addBefore(new Middleware\RequiredDatasetSync())
			->addAfter(new Middleware\Logger($this->logger))
		;
	}

	// region Infrastructure no-ops

	/**
	 * @inheritDoc
	 */
	public function registerPortal(): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 */
	public function verifyPortal(): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 */
	public function startSuperset(string $biconnectorToken = ''): IntegratorResponse
	{
		$handler = function (): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			return new IntegratorResponse(
				status: IntegratorResponse::STATUS_CREATED,
				data: [
					'token' => '',
					'superset_address' => $this->connectionService->getSupersetHost(),
				],
			);
		};

		return $this
			->createDefaultRequest('selfhosted/instance/start')
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function freezeSuperset(array $params = []): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 */
	public function unfreezeSuperset(array $params = []): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 */
	public function suspendSuperset(array $params = []): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 */
	public function resumeSuperset(array $params = []): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 */
	public function deleteSuperset(): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	/**
	 * @inheritDoc
	 *
	 * Self-hosted requests never arbitrate the instance status, so $arbitrateInstanceStatus has no effect here.
	 */
	public function changeBiconnectorToken(
		string $biconnectorToken,
		bool $arbitrateInstanceStatus = true,
	): IntegratorResponse
	{
		$handler = function () use ($biconnectorToken): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new ChangeDatabaseTokenCommand($server, $biconnectorToken))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
		};

		return $this
			->createDefaultRequest('selfhosted/instance/changeToken')
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function refreshDomainConnection(): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK);
	}

	// endregion

	// region Dashboard

	/**
	 * @inheritDoc
	 */
	public function getDashboardList(array $ids): IntegratorResponse
	{
		if (empty($ids))
		{
			return $this->buildDataResponse(
				new Dto\DashboardList(dashboards: [], commonCount: 0),
			);
		}

		$handler = function () use ($ids): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->list($ids);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();
			$dashboards = [];
			foreach (($data['dashboards'] ?? []) as $dashboardData)
			{
				$dashboards[] = $this->mapDashboardDto($dashboardData);
			}

			return $this->buildDataResponse(new Dto\DashboardList(
				dashboards: $dashboards,
				commonCount: $data['common_count'] ?? count($dashboards),
			));
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/list')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardListByFilter(array $filter = []): IntegratorResponse
	{
		$ids = (isset($filter['ids']) && is_array($filter['ids'])) ? $filter['ids'] : [];
		$neqIds = (isset($filter['neqIds']) && is_array($filter['neqIds'])) ? $filter['neqIds'] : [];

		$handler = function () use ($ids, $neqIds): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->list($ids, $neqIds);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();
			$dashboards = [];
			foreach (($data['dashboards'] ?? []) as $dashboardData)
			{
				$dashboards[] = $this->mapDashboardDto($dashboardData);
			}

			return $this->buildDataResponse(new Dto\DashboardList(
				dashboards: $dashboards,
				commonCount: $data['common_count'] ?? count($dashboards),
			));
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/listByFilter')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardById(int $dashboardId): IntegratorResponse
	{
		$handler = function () use ($dashboardId): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->getById($dashboardId);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$dashboardData = $result->getData()['dashboard'] ?? null;

			return $this->buildDataResponse($dashboardData ? $this->mapDashboardDto($dashboardData) : null);
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/get')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardEmbeddedCredentials(int $dashboardId, array $rlsRules = [], int $expSeconds = 0): IntegratorResponse
	{
		$handler = function (IntegratorRequest $request) use ($dashboardId, $rlsRules, $expSeconds): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$externalId = $this->resolveRequestUserExternalId($request, $server);
			if ($externalId === null)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Current superset user not found'))
				);
			}

			$userResult = (new UserProvider($server))->getById($externalId);
			if (!$userResult->isSuccess())
			{
				return $this->buildErrorResponse($userResult);
			}

			$userData = $userResult->getData()['user'] ?? null;
			if (!is_array($userData))
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Could not fetch superset user data'))
				);
			}

			$result = (new DashboardProvider($server))->issueGuestToken($dashboardId, $userData, $rlsRules, $expSeconds);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse(new Dto\DashboardEmbeddedCredentials(
				uuid: $data['uuid'] ?? '',
				guestToken: $data['guest_token'] ?? '',
				supersetDomain: $data['domain'] ?? '',
			));
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/embedded/get')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function copyDashboard(int $dashboardId, string $name): IntegratorResponse
	{
		$handler = function () use ($dashboardId, $name): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$ownerId = $this->getCurrentUserExternalId($server);
			if ($ownerId === null)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Current superset user not found'))
				);
			}

			$result = (new CopyDashboardCommand($server, $dashboardId, $name, $ownerId))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['dashboard'] ?? null);
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/copy')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function exportDashboard(int $dashboardId, array $dashboardSettings = []): IntegratorResponse
	{
		$handler = function () use ($dashboardId, $dashboardSettings): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

				$result = (new ExportDashboardCommand($server, $dashboardId, $dashboardSettings))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();
			$content = $data['body'] ?? '';
			if (is_string($content) && str_starts_with($content, 'UEs'))
			{
				$content = base64_decode($content);
			}

			if (empty($content))
			{
				return new IntegratorResponse(
					status: IntegratorResponse::STATUS_SERVER_ERROR,
					errors: [new Error("File content is empty. DashboardId: {$dashboardId}")],
				);
			}

			$dashboardName = SupersetDashboardTable::getRow([
				'select' => ['TITLE'],
				'filter' => ['=EXTERNAL_ID' => $dashboardId],
			])['TITLE'] ?? 'dashboard';

			$filePath = \CTempFile::GetFileName(md5(uniqid('bic', true)));
			$file = new IO\File($filePath);
			$contentSize = $file->putContents($content);

			$fileArray = \CFile::MakeFileArray($filePath);
			$fileArray['MODULE_ID'] = 'biconnector';
			$fileArray['name'] = $dashboardName . '.zip';

			$fileId = \CFile::SaveFile($fileArray, 'biconnector/dashboard_export');
			if ((int)$fileId <= 0)
			{
				return new IntegratorResponse(
					status: IntegratorResponse::STATUS_SERVER_ERROR,
					errors: [new Error("Exported file was not saved. DashboardId: {$dashboardId}")],
				);
			}

			$newFile = \CFile::GetByID($fileId)->Fetch();

			return $this->buildDataResponse([
				'filePath' => $newFile['SRC'],
				'contentSize' => $contentSize,
			]);
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/export')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDashboard(array $dashboardIds, bool $deleteRelatedEntities = false): IntegratorResponse
	{
		$handler = function () use ($dashboardIds, $deleteRelatedEntities): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DeleteDashboardsCommand($server, $dashboardIds, $deleteRelatedEntities))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/delete')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function importDashboard(string $filePath, string $appCode, string $type = '', bool $forceImportDatasets = false): IntegratorResponse
	{
		$handler = function () use ($filePath, $appCode, $type, $forceImportDatasets): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$archiveFile = ArchiveFileDto::fromArray(\CFile::MakeFileArray($filePath));
				$result = (new ImportDashboardCommand(
					$server,
					$archiveFile,
					CultureFormatter::getPortalCurrencySymbol(),
					CultureFormatter::getLanguageCode(),
					$appCode,
					$type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET,
					$forceImportDatasets,
				))->run();

			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/import')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function createEmptyDashboard(array $fields): IntegratorResponse
	{
		$handler = function () use ($fields): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$ownerId = $this->getCurrentUserExternalId($server);
			if ($ownerId === null)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Current superset user not found'))
				);
			}

			$fields['published'] = false;
			$result = (new CreateEmptyDashboardCommand($server, $fields, $ownerId))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/createEmpty')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function updateDashboard(int $dashboardId, array $editedFields): IntegratorResponse
	{
		$handler = function () use ($dashboardId, $editedFields): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new UpdateDashboardCommand($server, $dashboardId, $editedFields))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			if (isset($data['changed_fields']))
			{
				return $this->buildDataResponse($data['changed_fields']);
			}

			$keys = implode(', ', array_map('htmlspecialcharsbx', array_keys($editedFields)));
			$error = new Error("Update dashboard returns empty 'changed_fields'. Try to change: {$keys}");

			$response = $this->buildDataResponse($data);
			$response->addError($error);

			return $response;
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/update')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setDashboardOwner(int $dashboardId, User $user): IntegratorResponse
	{
		$handler = function () use ($dashboardId, $user): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = $this->getSupersetUser($server, $user->id);
			if ($supersetUser === null)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error("Superset user with id {$user->id} not found"))
				);
			}
			$result = (new SetDashboardOwnerCommand($server, $dashboardId, $supersetUser->getExternalId()))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/setOwner')
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardDatasets(int $dashboardId): IntegratorResponse
	{
		$handler = function () use ($dashboardId): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->getDatasets($dashboardId);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/datasets')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardReusedObjects(array $dashboardIds): IntegratorResponse
	{
		$handler = function () use ($dashboardIds): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->getReusedObjects($dashboardIds);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/getReusedObjects')
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardOverview(
		int $dashboardId,
		array $appliedFilters = [],
		array $urlParams = [],
		int $streamTimeout = 60 * 5,
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$handler = function () use ($dashboardId, $appliedFilters, $urlParams, $streamTimeout, $chartIds, $sort, $limit, $offset): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->getOverview(
				$dashboardId,
				$appliedFilters,
				$urlParams,
				$streamTimeout,
				$chartIds,
				$sort,
				$limit,
				$offset,
			);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/getOverview')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getFilterValues(
		int $dashboardId,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
		int $streamTimeout = 60,
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$handler = function () use (
			$dashboardId,
			$filterColumn,
			$appliedFilters,
			$urlParams,
			$search,
			$limit,
			$streamTimeout
		): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DashboardProvider($server))->getFilterValues(
				$dashboardId,
				$filterColumn,
				$appliedFilters,
				$urlParams,
				$search,
				$limit,
				$streamTimeout,
			);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dashboard/getFilterValues')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getChartList(array $ids): IntegratorResponse
	{
		if (empty($ids))
		{
			return $this->buildDataResponse([]);
		}

		$handler = function () use ($ids): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new ChartProvider($server))->list($ids);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['charts'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/chart/list')
			->setHandler($handler)
			->perform()
		;
	}

	// endregion

	// region User

	/**
	 * @inheritDoc
	 */
	public function createUser(User $user): IntegratorResponse
	{
		$handler = function () use ($user): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new CreateSupersetUserCommand($server, [
				'username' => $user->userName,
				'email' => $user->email,
				'first_name' => $user->firstName,
				'last_name' => $user->lastName,
			]))->run();

			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/create')
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getLoginUrl(): IntegratorResponse
	{
		$handler = function (): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$userId = CurrentUser::get()->getId();
			$supersetUser = $this->getSupersetUser($server, $userId);
			if (!$supersetUser)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Current user is not registered in Superset'))
				);
			}

			$result = (new UserProvider($server))->getLoginUrl($supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/getLoginUrl')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function updateUser(User $user): IntegratorResponse
	{
		$handler = function () use ($user): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = $this->getSupersetUser($server, $user->id);
			if (!$supersetUser)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error("User $user->id not found"))
				);
			}

			$result = (new UpdateSupersetUserCommand(
				$server,
				$supersetUser,
				[
					'first_name' => $user->firstName,
					'last_name' => $user->lastName,
				],
			))->run();

			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/update')
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function activateUser(User $user): IntegratorResponse
	{
		$handler = function () use ($user): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = $this->getSupersetUser($server, $user->id);
			if (!$supersetUser)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error("User $user->id not found"))
				);
			}

			$result = (new ActivateSupersetUserCommand($server, $supersetUser))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/activate')
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deactivateUser(User $user): IntegratorResponse
	{
		$handler = function () use ($user): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = $this->getSupersetUser($server, $user->id);
			if (!$supersetUser)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error("User $user->id not found"))
				);
			}

			$result = (new DeactivateSupersetUserCommand($server, $supersetUser))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/deactivate')
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setEmptyRole(User $user): IntegratorResponse
	{
		$handler = function () use ($user): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = $this->getSupersetUser($server, $user->id);
			if (!$supersetUser)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error("User $user->id not found"))
				);
			}

			$result = (new SetEmptySupersetUserRoleCommand($server, $supersetUser))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/setEmptyRole')
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function syncProfile(User $user, array $data): IntegratorResponse
	{
		$handler = function () use ($user, $data): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$dashboards = SupersetDashboardTable::getList([
				'select' => ['EXTERNAL_ID'],
				'filter' => [
					'=STATUS' => SupersetDashboard::getActiveDashboardStatuses(),
					'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
				],
				'cache' => ['ttl' => 3600],
			])->fetchAll();

			$fields = [
				'role' => $data['role'],
				'dashboardIdList' => $data['dashboardIdList'],
				'dashboardAllIdList' => array_map('intval', array_column($dashboards, 'EXTERNAL_ID')),
			];

			$supersetUser = $this->getSupersetUser($server, $user->id);
			if (!$supersetUser)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error("User $user->id not found"))
				);
			}

			$result = (new SyncSupersetUserProfileCommand($server, $supersetUser, $fields))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/user/syncProfile')
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	// endregion

	// region Dataset

	/**
	 * @inheritDoc
	 */
	public function getDatasetList(array $ids): IntegratorResponse
	{
		if (empty($ids))
		{
			return $this->buildDataResponse([]);
		}

		$handler = function () use ($ids): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->list($ids);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['datasets'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/list')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetById(int $id): IntegratorResponse
	{
		$handler = function () use ($id): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->getById($id);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/get')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetByName(string $name): IntegratorResponse
	{
		$handler = function () use ($name): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->getByName($name);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData()['dataset'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/getByName')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function createDataset(array $fields): IntegratorResponse
	{
		$handler = function () use ($fields): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$ownerId = $this->getCurrentUserExternalId($server);
			if ($ownerId === null)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Current superset user not found'))
				);
			}

			$result = (new CreateDatasetCommand($server, $fields, $ownerId))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/create')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function updateDataset(int $id, array $fields): IntegratorResponse
	{
		$handler = function () use ($id, $fields): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new UpdateDatasetCommand($server, $id, $fields))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/update')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDataset(int $id): IntegratorResponse
	{
		$handler = function () use ($id): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DeleteDatasetCommand($server, $id))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/delete')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetUrl(int $id): IntegratorResponse
	{
		$handler = function () use ($id): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->getUrlById($id);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/getUrl')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetCreateUrl(string $datasetName, bool $isVirtual = false): IntegratorResponse
	{
		$handler = function () use ($datasetName, $isVirtual): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->getCreateUrl($datasetName, $isVirtual);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/getCreateUrl')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetListByTableName(string $tableName): IntegratorResponse
	{
		if (empty($tableName))
		{
			return $this->buildDataResponse([]);
		}

		$handler = function () use ($tableName): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->listByTableName($tableName);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['datasets'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/listByTableName')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function initRequiredDataset(array $tables = []): IntegratorResponse
	{
		$handler = function () use ($tables): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

				$result = (new InitRequiredDatasetCommand($server, $tables))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/initRequiredDataset')
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	// endregion

	// region Unused elements

	/**
	 * @inheritDoc
	 */
	public function getUnusedElements(array $params): IntegratorResponse
	{
		$handler = function () use ($params): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$ormParams = [
				'page' => $params['page'] ?? 0,
				'pageSize' => $params['pageSize'] ?? 20,
				'order' => $params['order'] ?? null,
				'filter' => $params['filter'] ?? null,
			];

			$result = (new UnusedElementsProvider($server))->list($ormParams);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/unusedElements/get')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteUnusedElements(array $elements): IntegratorResponse
	{
		$handler = function () use ($elements): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

				$result = (new DeleteUnusedElementsCommand($server, $elements))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/unusedElements/delete')
			->setHandler($handler)
			->perform()
		;
	}

	// endregion

	// region Cache & misc

	/**
	 * @inheritDoc
	 */
	public function clearCache(): IntegratorResponse
	{
		$handler = function (): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new InvalidateSupersetCacheCommand($server))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/cache/clear')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setTimezone(string $timezone): IntegratorResponse
	{
		$handler = function () use ($timezone): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new SetServerTimezoneCommand($server, $timezone))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/timezone/set')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setExpirationDate(?Date $date): IntegratorResponse
	{
		$handler = function () use ($date): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$timestamp = $date?->getTimestamp() ?? 0;
			$result = (new SetSubscriptionExpirationCommand($server, $timestamp))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/subscription/expiration')
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function syncMarketDashboards(array $marketDashboardsIdList): IntegratorResponse
	{
		$handler = function () use ($marketDashboardsIdList): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new SyncSubscriptionRequireCommand($server, $marketDashboardsIdList))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/subscription/sync')
			->setHandler($handler)
			->perform()
		;
	}

	// endregion

	// region Internal helpers

	private function buildDataResponse(mixed $data): IntegratorResponse
	{
		return new IntegratorResponse(status: IntegratorResponse::STATUS_OK, data: $data);
	}

	private function buildServerNotConfiguredResponse(): IntegratorResponse
	{
		return new IntegratorResponse(
			status: IntegratorResponse::STATUS_SERVER_ERROR,
			errors: [new Error('Superset module is not available or server is not configured.')],
		);
	}

	private function buildErrorResponse(Result $result): IntegratorResponse
	{
		$errorCode = IntegratorResponse::STATUS_SERVER_ERROR;
		$errors = $result->getErrors();
		$this->logger->logErrors($errors);
		if (!empty($errors))
		{
			$code = (int)$errors[0]->getCode();
			if ($code > 0)
			{
				$errorCode = $code;
			}
		}

		return new IntegratorResponse(
			status: $errorCode,
			errors: $errors,
		);
	}

	private function getServer(): ?ServerReferenceDto
	{
		if ($this->server !== null)
		{
			return $this->server;
		}

		$this->server = $this->connectionService->findServerReference();

		return $this->server;
	}

	private function getCurrentUserExternalId(ServerReferenceDto $server): ?int
	{
		$bitrixUserId = CurrentUser::get()->getId();

		return $this->getSupersetUser($server, $bitrixUserId)?->getExternalId();
	}

	/**
	 * Resolves the Superset external user id for the request.
	 *
	 * Prefers the user already resolved by the UserAccess middleware.
	 *
	 * @param IntegratorRequest $request
	 * @param ServerReferenceDto $server
	 *
	 * @return int|null
	 */
	private function resolveRequestUserExternalId(IntegratorRequest $request, ServerReferenceDto $server): ?int
	{
		$user = $request->getUser();
		if ($user !== null)
		{
			return $this->getSupersetUser($server, $user->id)?->getExternalId();
		}

		return $this->getCurrentUserExternalId($server);
	}

	private function getSupersetUser(ServerReferenceDto $server, int $bitrixUserId): ?SupersetUserReferenceDto
	{
		$biUser = SupersetUserTable::getList([
			'select' => ['CLIENT_ID'],
			'filter' => ['=USER_ID' => $bitrixUserId],
			'limit' => 1,
			'cache' => ['ttl' => 3600],
		])->fetch();

		if (!$biUser || empty($biUser['CLIENT_ID']))
		{
			return null;
		}

		$localResult = (new UserProvider($server))->getLocalByClientId($biUser['CLIENT_ID']);
		if (!$localResult->isSuccess())
		{
			return null;
		}

		/** @var SupersetUserRuntimeDto $supersetUser */
		$supersetUser = $localResult->getData()['user'] ?? null;
		if (!$supersetUser)
		{
			return null;
		}

		return new SupersetUserReferenceDto(
			externalId: $supersetUser->getExternalId(),
			login: $supersetUser->getLogin(),
			clientId: $supersetUser->getClientId(),
		);
	}

	private function mapDashboardDto(array $data): Dto\Dashboard
	{
		$jsonMetadata = [];
		$rawMetadata = $data['json_metadata'] ?? '';
		if (is_string($rawMetadata) && $rawMetadata !== '')
		{
			try
			{
				$jsonMetadata = Json::decode($rawMetadata);
			}
			catch (\Exception)
			{
			}
		}
		elseif (is_array($rawMetadata))
		{
			$jsonMetadata = $rawMetadata;
		}

		$dateModify = null;
		$timestampModify = $data['timestamp_modify'] ?? null;
		if ($timestampModify !== null)
		{
			$dateModify = DateTime::createFromTimestamp((int)$timestampModify);
		}

		return new Dto\Dashboard(
			id: (int)($data['id'] ?? 0),
			title: $data['title'] ?? '',
			url: $data['url'] ?? '',
			editUrl: $data['edit_url'] ?? '',
			isEditable: $data['is_editable'] ?? false,
			published: $data['published'] ?? true,
			nativeFilterConfig: $jsonMetadata['native_filter_configuration'] ?? [],
			dateModify: $dateModify,
		);
	}

	private static function isAiToolsFeatureEnabled(): bool
	{
		return Feature::isBitrixGptBiConstructorEnabled();
	}

	private static function aiToolsDisabledResponse(): IntegratorResponse
	{
		return new IntegratorResponse(
			status: IntegratorResponse::STATUS_NO_ACCESS,
			errors: [new Error('AI tools are disabled.', 'ai_tools_disabled')],
		);
	}

	// endregion
}
