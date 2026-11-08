<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\SqlExecutionContext;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorEventLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Registrar;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\SupersetStatusOptionContainer;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\IO;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class ProxyIntegrator implements IntegratorInterface
{
	private const PROXY_ACTION_REGISTER_PORTAL = '/portal/register';
	private const PROXY_ACTION_VERIFY_PORTAL = '/portal/verify';
	private const PROXY_ACTION_START_SUPERSET = '/instance/start';
	private const PROXY_ACTION_FREEZE_SUPERSET = '/instance/freeze';
	private const PROXY_ACTION_UNFREEZE_SUPERSET = '/instance/unfreeze';
	private const PROXY_ACTION_SUSPEND_SUPERSET = '/instance/suspend';
	private const PROXY_ACTION_RESUME_SUPERSET = '/instance/resume';
	private const PROXY_ACTION_DELETE_SUPERSET = '/instance/delete';
	private const PROXY_ACTION_CHANGE_BI_TOKEN_SUPERSET = '/instance/changeToken';
	private const PROXY_ACTION_REFRESH_DOMAIN_CONNECTION = '/instance/refreshDomain';
	private const PROXY_ACTION_CLEAR_CACHE = '/instance/clearCache';
	private const PROXY_ACTION_CLEAR_PERMISSION_CACHE = '/instance/clearPermissionCache';
	private const PROXY_ACTION_TIMEZONE_SET = '/timezone/set';
	private const PROXY_ACTION_LIST_DASHBOARD = '/dashboard/list';
	private const PROXY_ACTION_DASHBOARD_DETAIL = '/dashboard/get';
	private const PROXY_ACTION_GET_EMBEDDED_DASHBOARD_CREDENTIALS = '/dashboard/embedded/get';
	private const PROXY_ACTION_GET_DEMO_EMBEDDED_CREDENTIALS = '/dashboard/demo/embedded/get';
	private const PROXY_ACTION_COPY_DASHBOARD = '/dashboard/copy';
	private const PROXY_ACTION_EXPORT_DASHBOARD = '/dashboard/export';
	private const PROXY_ACTION_DELETE_DASHBOARD = '/dashboard/delete';
	private const PROXY_ACTION_IMPORT_DASHBOARD = '/dashboard/import';
	private const PROXY_ACTION_CREATE_USER = '/user/create';
	private const PROXY_ACTION_GET_LOGIN_URL = '/user/getLoginUrl';
	private const PROXY_ACTION_UPDATE_USER = '/user/update';
	private const PROXY_ACTION_USER_ACTIVATE = '/user/activate';
	private const PROXY_ACTION_USER_DEACTIVATE = '/user/deactivate';
	private const PROXY_ACTION_USER_SET_EMPTY_ROLE = '/user/setEmptyRole';
	private const PROXY_ACTION_USER_SYNC_PROFILE = '/user/syncProfile';
	private const PROXY_ACTION_UPDATE_DASHBOARD = '/dashboard/update';
	private const PROXY_ACTION_CREATE_EMPTY_DASHBOARD = '/dashboard/createEmpty';
	private const PROXY_ACTION_SET_DASHBOARD_OWNER = '/dashboard/setOwner';
	private const PROXY_ACTION_GET_DATASET = '/dataset/get';
	private const PROXY_ACTION_GET_DATASET_BY_NAME = '/dataset/getByName';
	private const PROXY_ACTION_CREATE_DATASET = '/dataset/create';
	private const PROXY_ACTION_CREATE_VIRTUAL_DATASET = '/dataset/createVirtual';
	private const PROXY_ACTION_CREATE_DATASET_FROM_SQL = '/dataset/createFromSql';
	private const PROXY_ACTION_UPDATE_DATASET = '/dataset/update';
	private const PROXY_ACTION_DELETE_DATASET = '/dataset/delete';
	private const PROXY_ACTION_GET_DATASET_URL = '/dataset/getUrl';
	private const PROXY_ACTION_GET_DATASET_CREATE_URL = '/dataset/getCreateUrl';
	private const PROXY_ACTION_LIST_TABLES = '/table/list';
	private const PROXY_ACTION_GET_TABLE_METADATA = '/table/getMetadata';
	private const PROXY_ACTION_SQLLAB_EXECUTE = '/sqllab/execute';
	private const PROXY_ACTION_GET_CHART = '/chart/get';
	private const PROXY_ACTION_CREATE_CHART = '/chart/create';
	private const PROXY_ACTION_UPDATE_CHART = '/chart/update';
	private const PROXY_ACTION_DELETE_CHART = '/chart/delete';
	private const PROXY_ACTION_GET_UNUSED_ELEMENTS = '/unusedElements/get';
	private const PROXY_ACTION_DELETE_UNUSED_ELEMENTS = '/unusedElements/delete';
	private const PROXY_ACTION_GET_DASHBOARD_DATASETS = '/dashboard/datasets';
	private const PROXY_ACTION_LIST_DATASET_BY_TABLE_NAME = '/dataset/listByTableName';
	private const PROXY_ACTION_DATASET_INIT_REQUIRED_DATASET = '/dataset/initRequiredDataset';
	private const PROXY_ACTION_GET_DASHBOARD_REUSED_OBJECTS = '/dashboard/getReusedObjects';
	private const PROXY_ACTION_SET_SUBSCRIPTION_EXPIRATION = '/subscription/expiration';
	private const PROXY_ACTION_SET_SUBSCRIPTION_REQUIRE = '/subscription/require';
	private const PROXY_ACTION_GET_DASHBOARD_OVERVIEW = '/dashboard/getOverview';
	private const PROXY_ACTION_GET_FILTER_VALUES = '/dashboard/getFilterValues';
	private const PROXY_ACTION_LIST_CHART = '/chart/list';
	private const PROXY_ACTION_LIST_DATASET = '/dataset/list';
	private const PROXY_ACTION_LIST_PHYSICAL_DATASET = '/dataset/listPhysical';

	private const PROXY_EXTENDED_STREAM_TIMEOUT = 90;
	private const PROXY_DEMO_STREAM_TIMEOUT = 8;

	/**
	 * Timeouts of the permission cache reset. The connection bound is short - the reset carries no data and is
	 * sent from the background job of an ordinary hit, so a person saving a role or a role relation waits out
	 * whatever this call waits out, and an instance that does not answer at all has to cost them seconds.
	 *
	 * The answer bound is deliberately NOT short, and this is the non-obvious half. A slow but successful reset
	 * must not be written down as a failure: the mark then stays pending and the tail agent re-sends it every
	 * retry window for as long as the scan stays slow, so the cost of giving up too early is paid over and over.
	 *
	 * The service bounds each operation of its own and not the call, so the call as a whole has no upper bound
	 * there and this value is the only one there is. Two consequences follow and neither is visible from here.
	 * We give up first, and the service keeps scanning after we do - a repeat then lays a second scan over the
	 * first on the shared cache of the engine. And there is no external number to stay above: the value is
	 * chosen so that an ordinary reset, whose keys number the users of the portal, finishes well inside it. A
	 * call that does not is a malfunction rather than a large portal.
	 */
	private const PERMISSION_CACHE_RESET_SOCKET_TIMEOUT = 5;
	private const PERMISSION_CACHE_RESET_STREAM_TIMEOUT = 30;
	static private self $instance;

	private Sender $sender;
	private IntegratorLogger $logger;

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	protected function createDefaultRequest(string $action): IntegratorRequest
	{
		$statusContainer = new SupersetStatusOptionContainer();

		$registrarMiddleware = new Middleware\Registrar(Registrar::getRegistrar(), $this->logger);
		$domainLinkGateMiddleware = new Middleware\DomainLinkGate($this->logger);
		$request = (new IntegratorRequest($this->sender))
			->setAction($action)
			->addBefore(new Middleware\TariffRestriction())
			->addBefore($registrarMiddleware)
			->addBefore($domainLinkGateMiddleware)
			->addBefore(new Middleware\ReadyGate($statusContainer, $this->logger))
			->addBefore(new Middleware\UserAccess())
			->addBefore(new Middleware\RequiredDatasetSync())
			->addAfter($registrarMiddleware)
			->addAfter($domainLinkGateMiddleware)
			->addAfter(new Middleware\Logger($this->logger))
			->addAfter(new Middleware\StatusArbiter($this->logger))
		;

		return $request;
	}

	private static function getDefaultLogger(): IntegratorLogger
	{
		return new IntegratorEventLogger();
	}

	private function __construct()
	{
		$this->sender = new Sender();
		$this->logger = self::getDefaultLogger();
	}

	public function setSender(Sender $sender): void
	{
		$this->sender = $sender;
	}

	// region Service methods

	/**
	 * @inheritDoc
	 */
	public function registerPortal(): IntegratorResponse
	{
		$response = $this
			->createDefaultRequest(self::PROXY_ACTION_REGISTER_PORTAL)
			->removeBefore(Middleware\ReadyGate::getMiddlewareId())
			->removeBefore(Middleware\DomainLinkGate::getMiddlewareId())
			->removeBefore(Middleware\Registrar::getMiddlewareId())
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->removeAfter(Middleware\DomainLinkGate::getMiddlewareId())
			->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
			->perform()
		;

		$clientId = $response->getData()['portalId'] ?? null;
		$isRebind = (bool)($response->getData()['rebind'] ?? false);

		if ($response->hasErrors() && $isRebind)
		{
			Option::set('biconnector', '~superset_rebind_required', 'Y');
		}

		return $response->setData(['portalId' => $clientId, 'rebind' => $isRebind]);
	}

	/**
	 * @inheritDoc
	 */
	public function verifyPortal(): IntegratorResponse
	{
		return $this
			->createDefaultRequest(self::PROXY_ACTION_VERIFY_PORTAL)
			->removeBefore(Middleware\ReadyGate::getMiddlewareId())
			->removeBefore(Middleware\DomainLinkGate::getMiddlewareId())
			->removeBefore(Middleware\Registrar::getMiddlewareId())
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->removeAfter(Middleware\DomainLinkGate::getMiddlewareId())
			->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardList(array $ids): IntegratorResponse
	{
		if (empty($ids))
		{
			return new IntegratorResponse(
				status: IntegratorResponse::STATUS_OK,
				data: new Dto\DashboardList(
					dashboards: [],
					commonCount: 0,
				)
			);
		}

		$requestParams = [
			'ids' => $ids,
		];

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_DASHBOARD)
				->setParams($requestParams)
				->perform()
		;

		return $this->parseDashboardListResponse($response);
	}

	/**
	 * Returns filtered list of dashboards from Superset.
	 *
	 * @param array $filter Proxy filter params. Allowed keys: ids, neqIds, ownedByRequestUser.
	 * @return IntegratorResponse<Dto\DashboardList>
	 */
	public function getDashboardListByFilter(array $filter = []): IntegratorResponse
	{
		$requestFilter = [];
		if (isset($filter['ids']) && is_array($filter['ids']))
		{
			$requestFilter['ids'] = $filter['ids'];
		}

		if (isset($filter['neqIds']) && is_array($filter['neqIds']))
		{
			$requestFilter['neqIds'] = $filter['neqIds'];
		}

		$ownedByRequestUser = !empty($filter['ownedByRequestUser']);
		if ($ownedByRequestUser)
		{
			$requestFilter['ownedByRequestUser'] = true;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_DASHBOARD)
				->setParams($requestFilter)
				->perform()
		;

		if ($ownedByRequestUser && !$response->hasErrors() && !$this->isOwnerFilterApplied($response))
		{
			return new IntegratorResponse(
				status: IntegratorResponse::STATUS_NO_ACCESS,
				errors: [new Error('Owner filter of the dashboard list was not applied by the proxy')],
			);
		}

		return $this->parseDashboardListResponse($response);
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardById(
		int $dashboardId,
		int $streamTimeout = 0,
		bool $arbitrateServiceFailure = true,
	): IntegratorResponse
	{
		$request =
			$this
				->createDashboardDetailRequest($arbitrateServiceFailure)
				->setParams(['id' => $dashboardId])
		;

		if ($streamTimeout > 0)
		{
			$request->setSenderHttpClientParams([
				'streamTimeout' => $streamTimeout,
			]);
		}

		$response = $request->perform();

		if ($response->hasErrors())
		{
			return $response;
		}

		$responseData = $response->getData();
		$dashboardData = $responseData['dashboard'] ?? null;

		$dashboard = null;
		if ($dashboardData)
		{
			$jsonMetadata = $this->decode($dashboardData['json_metadata']) ?? [];
			$dateModify = null;
			if (isset($dashboardData['timestamp_modify']))
			{
				$dateModify = DateTime::createFromTimestamp((int)$dashboardData['timestamp_modify']);
			}

			$dashboard = new Dto\Dashboard(
				id: $dashboardData['id'],
				title: $dashboardData['title'],
				url: $dashboardData['url'] ?? '',
				editUrl: $dashboardData['edit_url'] ?? '',
				isEditable: $dashboardData['is_editable'] ?? false,
				published: $dashboardData['published'] ?? true,
				nativeFilterConfig: $jsonMetadata['native_filter_configuration'] ?? [],
				dateModify: $dateModify,
				jsonMetadata: $jsonMetadata,
			);
		}

		return $response->setData($dashboard);
	}

	private function createDashboardDetailRequest(bool $arbitrateServiceFailure): IntegratorRequest
	{
		return $this->createArbitratedRequest(self::PROXY_ACTION_DASHBOARD_DETAIL, $arbitrateServiceFailure);
	}

	/**
	 * The arbiter keeps its place at the end of the after-pipeline: it is re-added, not reordered.
	 */
	private function createArbitratedRequest(string $action, bool $arbitrateServiceFailure): IntegratorRequest
	{
		$request = $this->createDefaultRequest($action);
		if (!$arbitrateServiceFailure)
		{
			$request
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->addAfter(new Middleware\StatusArbiter($this->logger, arbitrateServiceFailure: false))
			;
		}

		return $request;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardEmbeddedCredentials(int $dashboardId, array $rlsRules = [], int $expSeconds = 0): IntegratorResponse
	{
		$requestParams = [
			'id' => $dashboardId,
		];

		if (!empty($rlsRules))
		{
			$requestParams['rlsRules'] = $rlsRules;
		}

		if ($expSeconds > 0)
		{
			$requestParams['expSeconds'] = $expSeconds;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_EMBEDDED_DASHBOARD_CREDENTIALS)
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$credentialsData = $response->getData();
		if ($credentialsData)
		{
			$response->setData(new Dto\DashboardEmbeddedCredentials(
				uuid: $credentialsData['uuid'],
				guestToken: $credentialsData['guest_token'],
				supersetDomain: $credentialsData['domain'],
			));
		}

		return $response;
	}

	/**
	 * @inheritDoc
	 */
	public function getDemoEmbeddedCredentials(string $reportCode, string $lang): IntegratorResponse
	{
		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_DEMO_EMBEDDED_CREDENTIALS)
				->setParams([
					'reportCode' => $reportCode,
					'lang' => $lang,
				])
				->setSenderHttpClientParams([
					'streamTimeout' => self::PROXY_DEMO_STREAM_TIMEOUT,
				])
				->removeBefore(Middleware\TariffRestriction::getMiddlewareId())
				->removeBefore(Middleware\Registrar::getMiddlewareId())
				->removeBefore(Middleware\DomainLinkGate::getMiddlewareId())
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeBefore(Middleware\UserAccess::getMiddlewareId())
				->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
				->removeAfter(Middleware\Registrar::getMiddlewareId())
				->removeAfter(Middleware\DomainLinkGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->removeAfter(Middleware\Logger::getMiddlewareId())
				->perform()
		;

		if (
			$response->getStatus() === IntegratorResponse::STATUS_TOO_MANY_REQUESTS
			|| $response->hasErrors()
		)
		{
			return $response;
		}

		$credentialsData = $response->getData();
		if (isset($credentialsData['uuid'], $credentialsData['guest_token'], $credentialsData['domain']))
		{
			$response->setData(new Dto\DashboardEmbeddedCredentials(
				uuid: $credentialsData['uuid'],
				guestToken: $credentialsData['guest_token'],
				supersetDomain: $credentialsData['domain'],
			));
		}

		return $response;
	}

	/**
	 * @inheritDoc
	 */
	public function updateUser(Dto\User $user): IntegratorResponse
	{
		$parameters = [
			'first_name' => $user->firstName,
			'last_name' => $user->lastName,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_UPDATE_USER)
				->setParams(['fields' => $parameters])
				->setUser($user)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function activateUser(Dto\User $user): IntegratorResponse
	{
		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_USER_ACTIVATE)
				->setUser($user)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deactivateUser(Dto\User $user): IntegratorResponse
	{
		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_USER_DEACTIVATE)
				->setUser($user)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setEmptyRole(Dto\User $user): IntegratorResponse
	{
		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_USER_SET_EMPTY_ROLE)
				->setUser($user)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function copyDashboard(int $dashboardId, string $name): IntegratorResponse
	{
		$requestParams = [
			'id' => $dashboardId,
			'name' => $name,
		];

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_COPY_DASHBOARD)
				->setParams($requestParams)
				->perform()
		;

		return $response->setData($response->getData()['dashboard']);
	}

	/**
	 * @inheritDoc
	 */
	public function exportDashboard(int $dashboardId, array $dashboardSettings = []): IntegratorResponse
	{
		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_EXPORT_DASHBOARD)
				->setParams(
					[
						'id' => $dashboardId,
						'dashboardSettings' => $dashboardSettings,
					]
				)
				->setSenderHttpClientParams([
					'streamTimeout' => self::PROXY_EXTENDED_STREAM_TIMEOUT,
				])
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$content = $response->getData()['body'];
		$content = base64_decode($content);
		if ($content <= 0)
		{
			$this->logger->logMethodErrors(
				self::PROXY_ACTION_EXPORT_DASHBOARD,
				'400',
				[
					new Error("File content is empty. DashboardId: $dashboardId"),
				]
			);

			return $response;
		}

		$dashboardRow = SupersetDashboardTable::getRow([
			'select' => ['TITLE'],
			'filter' => [
				'=EXTERNAL_ID' => $dashboardId,
			],
		]);
		$dashboardName = $dashboardRow['TITLE'] ?? 'dashboard';
		$fileName = $dashboardName . '.zip';

		$filePath = \CTempFile::GetFileName(md5(uniqid('bic', true)));
		$file = new IO\File($filePath);
		$contentSize = $file->putContents($content);

		$file = \CFile::MakeFileArray($filePath);
		$file['MODULE_ID'] = 'biconnector';
		$file['name'] = $fileName;
		if (\CFile::CheckFile($file, strExt: 'zip') !== '')
		{
			$this->logger->logMethodErrors(
				self::PROXY_ACTION_EXPORT_DASHBOARD,
				'400',
				[
					new Error("Exported file was not found. DashboardId: $dashboardId"),
				]
			);

			return $response;
		}

		$fileId = \CFile::SaveFile($file, 'biconnector/dashboard_export');
		if ((int)$fileId <= 0)
		{
			$this->logger->logMethodErrors(
				self::PROXY_ACTION_EXPORT_DASHBOARD,
				'400',
				[
					new Error("Exported file was not saved. DashboardId: $dashboardId"),
				]
			);
		}
		$newFile = \CFile::GetByID($fileId)->Fetch();

		$responseData = [
			'filePath' => $newFile['SRC'],
			'contentSize' => $contentSize,
		];

		return $response->setData($responseData);
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDashboard(array $dashboardIds, bool $deleteRelatedEntities = false): IntegratorResponse
	{

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_DELETE_DASHBOARD)
				->setParams(['ids' => $dashboardIds, 'deleteRelatedEntities' => $deleteRelatedEntities])
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function startSuperset(string $biconnectorToken = ''): IntegratorResponse
	{
		$requestParams = ['biconnectorToken' => $biconnectorToken];
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			$requestParams['userName'] = Application::getConnection()->getDatabase();
		}

		$region = Application::getInstance()->getLicense()->getRegion();
		if (!empty($region))
		{
			$requestParams['region'] = $region;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_START_SUPERSET)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$responseData = [
			'token' => $response->getData()['token'],
			'superset_address' => $response->getData()['superset_address'] ?? null,
			'rebind' => (bool)($response->getData()['rebind'] ?? false),
		];

		return $response->setData($responseData);
	}

	/**
	 * @inheritDoc
	 */
	public function freezeSuperset(array $params = []): IntegratorResponse
	{
		$requestParams = [];
		if (isset($params['reason']))
		{
			$requestParams['reason'] = $params['reason'];
		}

		return (new IntegratorRequest($this->sender))
			->setAction(self::PROXY_ACTION_FREEZE_SUPERSET)
			->setParams($requestParams)
			->addAfter(new Middleware\Logger($this->logger))
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function unfreezeSuperset(array $params = []): IntegratorResponse
	{
		$requestParams = [];
		if (isset($params['reason']))
		{
			$requestParams['reason'] = $params['reason'];
		}

		return (new IntegratorRequest($this->sender))
			->setAction(self::PROXY_ACTION_UNFREEZE_SUPERSET)
			->setParams($requestParams)
			->addAfter(new Middleware\Logger($this->logger))
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function suspendSuperset(array $params = []): IntegratorResponse
	{
		$requestParams = [];
		if (isset($params['reason']))
		{
			$requestParams['reason'] = $params['reason'];
		}

		return (new IntegratorRequest($this->sender))
			->setAction(self::PROXY_ACTION_SUSPEND_SUPERSET)
			->setParams($requestParams)
			->addAfter(new Middleware\Logger($this->logger))
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function resumeSuperset(array $params = []): IntegratorResponse
	{
		$requestParams = [];
		if (isset($params['reason']))
		{
			$requestParams['reason'] = $params['reason'];
		}

		return (new IntegratorRequest($this->sender))
			->setAction(self::PROXY_ACTION_RESUME_SUPERSET)
			->setParams($requestParams)
			->addAfter(new Middleware\Logger($this->logger))
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteSuperset(): IntegratorResponse
	{
		return (new IntegratorRequest($this->sender))
			->setAction(self::PROXY_ACTION_DELETE_SUPERSET)
			->addAfter(new Middleware\Logger($this->logger))
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function changeBiconnectorToken(
		string $biconnectorToken,
		bool $arbitrateInstanceStatus = true,
	): IntegratorResponse
	{
		return
			$this
				->createChangeBiconnectorTokenRequest($arbitrateInstanceStatus)
				->setParams(['biconnectorToken' => $biconnectorToken])
				->perform()
		;
	}

	private function createChangeBiconnectorTokenRequest(bool $arbitrateInstanceStatus): IntegratorRequest
	{
		$request = $this->createDefaultRequest(self::PROXY_ACTION_CHANGE_BI_TOKEN_SUPERSET);
		if (!$arbitrateInstanceStatus)
		{
			$request->removeAfter(Middleware\StatusArbiter::getMiddlewareId());
		}

		return $request;
	}

	/**
	 * @inheritDoc
	 */
	public function clearCache(): IntegratorResponse
	{
		return $this->createDefaultRequest(self::PROXY_ACTION_CLEAR_CACHE)->perform();
	}

	/**
	 * @inheritDoc
	 */
	public function clearPermissionCache(): IntegratorResponse
	{
		return $this
			->createDefaultRequest(self::PROXY_ACTION_CLEAR_PERMISSION_CACHE)
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->setSenderHttpClientParams([
				'socketTimeout' => self::PERMISSION_CACHE_RESET_SOCKET_TIMEOUT,
				'streamTimeout' => self::PERMISSION_CACHE_RESET_STREAM_TIMEOUT,
			])
			->perform()
		;
	}

	public function setTimezone(string $timezone): IntegratorResponse
	{
		return $this
			->createDefaultRequest(self::PROXY_ACTION_TIMEZONE_SET)
			->setParams(['timezone' => $timezone])
			->perform()
		;
	}

	public function refreshDomainConnection(): IntegratorResponse
	{
		return $this
			->createDefaultRequest(self::PROXY_ACTION_REFRESH_DOMAIN_CONNECTION)
			->removeBefore(Middleware\ReadyGate::getMiddlewareId())
			->removeBefore(Middleware\DomainLinkGate::getMiddlewareId())
			->removeAfter(Middleware\DomainLinkGate::getMiddlewareId())
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function createUser(Dto\User $user): IntegratorResponse
	{
		$action = self::PROXY_ACTION_CREATE_USER;
		$parameters = [
			'username' => $user->userName,
			'email' => $user->email,
			'first_name' => $user->firstName,
			'last_name' => $user->lastName,
		];

		return
			$this
				->createDefaultRequest($action)
				->setParams(['fields' => $parameters])
				->removeBefore(Middleware\UserAccess::getMiddlewareId())
				->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getLoginUrl(): IntegratorResponse
	{
		return $this->createDefaultRequest(self::PROXY_ACTION_GET_LOGIN_URL)->perform();
	}

	/**
	 * @inheritDoc
	 */
	public function importDashboard(
		string $filePath,
		string $appCode,
		string $type = '',
		bool $forceImportDatasets = false,
	): IntegratorResponse
	{

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_IMPORT_DASHBOARD)
				->setParams([
					'filePath' => $filePath,
					'currency' => CultureFormatter::getPortalCurrencySymbol(),
					'langCode' => CultureFormatter::getLanguageCode(),
					'appCode' => $appCode,
					'requiresSubscription' => $type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET,
					'forceImportDatasets' => $forceImportDatasets,
				])
				->setSenderHttpClientParams([
					'streamTimeout' => self::PROXY_EXTENDED_STREAM_TIMEOUT,
				])
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setMultipart(true)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function createEmptyDashboard(array $fields): IntegratorResponse
	{
		$fields['published'] = false;

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_CREATE_EMPTY_DASHBOARD)
				->setParams(['fields' => $fields])
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setDashboardOwner(int $dashboardId, Dto\User $user): IntegratorResponse
	{
		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_SET_DASHBOARD_OWNER)
				->setParams(['id' => $dashboardId])
				->setUser($user)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function syncProfile(Dto\User $user, array $data): IntegratorResponse
	{
		$dashboards = SupersetDashboardTable::getList([
			'select' => ['EXTERNAL_ID'],
			'filter' => [
				'=STATUS' => SupersetDashboard::getActiveDashboardStatuses(),
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
			],
			'cache' => ['ttl' => 3600],
		])->fetchAll();

		$parameters = [
			'role' => $data['role'],
			'dashboardIdList' => $data['dashboardIdList'],
			'dashboardAllIdList' => array_map('intval', array_column($dashboards, 'EXTERNAL_ID')),
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_USER_SYNC_PROFILE)
				->setParams(['fields' => $parameters])
				->setUser($user)
				->perform()
		;
	}


	/**
	 * @inheritDoc
	 */
	public function updateDashboard(int $dashboardId, array $editedFields): IntegratorResponse
	{
		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_UPDATE_DASHBOARD)
				->setParams([
					'id' => $dashboardId,
					'fields' => $editedFields,
				])
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();
		if (isset($resultData['changed_fields']))
		{
			$response->setData($resultData['changed_fields']);
		}
		else
		{
			$keys = [];
			foreach ($editedFields as $key => $val)
			{
				$keys[] = htmlspecialcharsbx($key);
			}

			$keys = implode(', ', $keys);
			$error = new Error("Update dashboard returns empty 'changed_fields'. Try to change: {$keys}");

			$this->logger->logMethodErrors(
				self::PROXY_ACTION_UPDATE_DASHBOARD,
				$response->getStatus(),
				[$error]
			);

			$response->addError($error);
		}

		return $response;
	}

	/**
	 * @inheritDoc
	 */
	public function getChartList(array $ids): IntegratorResponse
	{
		if (empty($ids))
		{
			return new IntegratorResponse(
				status: IntegratorResponse::STATUS_OK,
				data: [],
			);
		}

		$requestParams = [
			'ids' => $ids,
		];

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_CHART)
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		return $response->setData($resultData['charts'] ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetList(array $ids): IntegratorResponse
	{
		if (empty($ids))
		{
			return new IntegratorResponse(
				status: IntegratorResponse::STATUS_OK,
				data: [],
			);
		}

		$requestParams = [
			'ids' => $ids,
		];

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_DATASET)
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		return $response->setData($resultData['datasets'] ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function getPhysicalDatasetList(): IntegratorResponse
	{
		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_PHYSICAL_DATASET)
				->removeBefore(Middleware\UserAccess::getMiddlewareId())
				->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		return $response->setData($resultData['datasets'] ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetById(int $id): IntegratorResponse
	{
		$requestParams = [
			'id' => $id,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_DATASET)
				->setParams($requestParams)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetByName(string $name): IntegratorResponse
	{
		$requestParams = [
			'name' => $name,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_DATASET_BY_NAME)
				->setParams($requestParams)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function listDatasets(
		array $ids = [],
		array $neqIds = [],
		?int $page = null,
		?int $pageSize = null
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$requestParams = [];
		if (!empty($ids))
		{
			$requestParams['ids'] = array_values(array_map('intval', $ids));
		}
		if (!empty($neqIds))
		{
			$requestParams['neqIds'] = array_values(array_map('intval', $neqIds));
		}
		if ($page !== null)
		{
			$requestParams['page'] = $page;
		}
		if ($pageSize !== null)
		{
			$requestParams['pageSize'] = $pageSize;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_DATASET)
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		// Bounded mode keeps the full {datasets, count} envelope so the caller can
		// read the total; legacy mode returns a flat array for the by-ids /
		// idempotency callers that iterate the list directly.
		if ($pageSize !== null)
		{
			return $response->setData(is_array($resultData) ? $resultData : []);
		}

		return $response->setData($resultData['datasets'] ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function createDataset(array $fields): IntegratorResponse
	{
		$parameters = [
			'name' => $fields['name'],
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_CREATE_DATASET)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams(['fields' => $parameters])
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function createVirtualDataset(
		string $datasetName,
		string $sourceTable,
		array $columns,
		?string $sourceSchema = null,
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$parameters = [
			'datasetName' => $datasetName,
			'sourceTable' => $sourceTable,
			'columns' => array_values($columns),
		];
		if ($sourceSchema !== null && $sourceSchema !== '')
		{
			$parameters['sourceSchema'] = $sourceSchema;
		}

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_CREATE_VIRTUAL_DATASET)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams($parameters)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function createDatasetFromSql(string $datasetName, string $sql): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$parameters = [
			'datasetName' => $datasetName,
			'sql' => $sql,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_CREATE_DATASET_FROM_SQL)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams($parameters)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function executeSql(
		string $sql,
		?int $limit = null,
		?SqlExecutionContext $context = null,
	): IntegratorResponse
	{
		if (!($context?->isRest() ?? false) && !self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$params = ['sql' => $sql];
		if ($limit !== null)
		{
			$params['limit'] = $limit;
		}

		$request = $this->createDefaultRequest(self::PROXY_ACTION_SQLLAB_EXECUTE);
		if ($context !== null)
		{
			$request->setUser($context->user);
		}

		$response =
			$request
				->setParams($params)
				->setSenderHttpClientParams([
					'streamTimeout' => self::PROXY_EXTENDED_STREAM_TIMEOUT,
				])
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		return $response->setData($response->getData() ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function listTables(?string $schema = null): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$params = [];
		if ($schema !== null && $schema !== '')
		{
			$params['schema'] = $schema;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_TABLES)
				->setParams($params)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		return $response->setData($response->getData() ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function getTableMetadata(string $tableName, ?string $schema = null): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$params = [
			'name' => $tableName,
		];
		if ($schema !== null && $schema !== '')
		{
			$params['schema'] = $schema;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_TABLE_METADATA)
				->setParams($params)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		return $response->setData($response->getData() ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function updateDataset(int $id, array $fields): IntegratorResponse
	{
		$parameters = [];

		if (isset($fields['table_name']))
		{
			$parameters['table_name'] = $fields['table_name'];
		}

		if (isset($fields['columns']))
		{
			$parameters['columns'] = $fields['columns'];
		}

		if (isset($fields['description']))
		{
			$parameters['description'] = $fields['description'];
		}

		if (isset($fields['verbose_name']))
		{
			$parameters['verbose_name'] = $fields['verbose_name'];
		}

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_UPDATE_DATASET)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams([
					'id' => $id,
					'fields' => $parameters,
				])
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDataset(int $id): IntegratorResponse
	{
		$parameters = [
			'id' => $id,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_DELETE_DATASET)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams($parameters)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetUrl(int $id): IntegratorResponse
	{
		$parameters = [
			'id' => $id,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_DATASET_URL)
				->setParams($parameters)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetCreateUrl(string $datasetName, bool $isVirtual = false): IntegratorResponse
	{
		$parameters = [
			'datasetName' => $datasetName,
			'isVirtual' => $isVirtual,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_DATASET_CREATE_URL)
				->setParams($parameters)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function getUnusedElements(array $params): IntegratorResponse
	{
		$parameters = [
			'ormParams' => [
				'page' => $params['page'] ?? 0,
				'pageSize' => $params['pageSize'] ?? 20,
				'order' => $params['order'] ?? null,
				'filter' => $params['filter'] ?? null,
			],
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_UNUSED_ELEMENTS)
				->setParams($parameters)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteUnusedElements(array $elements): IntegratorResponse
	{
		$parameters = [
			'elements' => $elements,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_DELETE_UNUSED_ELEMENTS)
				->setParams($parameters)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardDatasets(int $dashboardId): IntegratorResponse
	{
		$parameters = [
			'id' => $dashboardId,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_DASHBOARD_DATASETS)
				->setParams($parameters)
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
			return new IntegratorResponse(
				status: IntegratorResponse::STATUS_OK,
				data: []
			);
		}

		$requestParams = [
			'tableName' => $tableName,
		];

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_DATASET_BY_TABLE_NAME)
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		return $response->setData($resultData['datasets'] ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function getDashboardReusedObjects(array $dashboardIds): IntegratorResponse
	{
		return $this
			->createDefaultRequest(self::PROXY_ACTION_GET_DASHBOARD_REUSED_OBJECTS)
			->setParams(['dashboardIds' => $dashboardIds])
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
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
		bool $arbitrateServiceFailure = true,
		?User $user = null,
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$params = ['id' => $dashboardId];
		if (!empty($appliedFilters))
		{
			$params['appliedFilters'] = $appliedFilters;
		}
		if (!empty($urlParams))
		{
			$params['urlParams'] = $urlParams;
		}
		if (!empty($chartIds))
		{
			$params['chartIds'] = array_values(array_map('intval', $chartIds));
			if (!empty($sort))
			{
				$params['sort'] = $sort;
			}
			if ($limit !== null)
			{
				$params['limit'] = $limit;
			}
			if ($offset > 0)
			{
				$params['offset'] = $offset;
			}
		}

		$request = $this
			->createArbitratedRequest(self::PROXY_ACTION_GET_DASHBOARD_OVERVIEW, $arbitrateServiceFailure)
			->setParams($params)
			->setSenderHttpClientParams([
				'streamTimeout' => $streamTimeout,
			])
		;
		if ($user !== null)
		{
			$request->setUser($user);
		}

		return $request->perform();
	}

	public function getFilterValues(
		int $dashboardId,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
		int $streamTimeout = 60,
		bool $arbitrateServiceFailure = true,
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$params = [
			'id' => $dashboardId,
			'filterColumn' => $filterColumn,
		];
		if (!empty($appliedFilters))
		{
			$params['appliedFilters'] = $appliedFilters;
		}
		if (!empty($urlParams))
		{
			$params['urlParams'] = $urlParams;
		}
		if ($search !== null && $search !== '')
		{
			$params['search'] = $search;
		}
		if ($limit !== null)
		{
			$params['limit'] = $limit;
		}

		return $this
			->createArbitratedRequest(self::PROXY_ACTION_GET_FILTER_VALUES, $arbitrateServiceFailure)
			->setParams($params)
			->setSenderHttpClientParams([
				'streamTimeout' => $streamTimeout,
			])
			->perform()
		;
	}

	private static function isAiToolsFeatureEnabled(): bool
	{
		return Feature::isBitrixGptBiConstructorEnabled();
	}

	private static function aiToolsDisabledResponse(): IntegratorResponse
	{
		$response = new IntegratorResponse(status: IntegratorResponse::STATUS_NO_ACCESS);
		$response->addError(new Error('AI tools are disabled.', 'ai_tools_disabled'));

		return $response;
	}

	/**
	 * Inits a server-side scenario to create or update required system datasets (e.g., for filters).
	 *
	 * @param array $tables
	 * @return IntegratorResponse
	 */
	public function initRequiredDataset(array $tables = []): IntegratorResponse
	{
		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_DATASET_INIT_REQUIRED_DATASET)
				->setParams(['tables' => $tables])
				->removeBefore(Middleware\UserAccess::getMiddlewareId())
				->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function setExpirationDate(?Date $date): IntegratorResponse
	{
		$parameters = [
			'timestamp' => self::normalizeExpirationTimestamp($date),
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_SET_SUBSCRIPTION_EXPIRATION)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams($parameters)
				->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function syncMarketDashboards(array $marketDashboardsIdList): IntegratorResponse
	{
		$parameters = [
			'dashboards' => $marketDashboardsIdList,
		];

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_SET_SUBSCRIPTION_REQUIRE)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams($parameters)
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function listCharts(
		array $ids = [],
		?string $nameFilter = null,
		?int $page = null,
		?int $pageSize = null
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$requestParams = [];
		if (!empty($ids))
		{
			$requestParams['ids'] = array_values(array_map('intval', $ids));
		}
		if ($nameFilter !== null && $nameFilter !== '')
		{
			$requestParams['nameFilter'] = $nameFilter;
		}
		if ($page !== null)
		{
			$requestParams['page'] = $page;
		}
		if ($pageSize !== null)
		{
			$requestParams['pageSize'] = $pageSize;
		}

		$response =
			$this
				->createDefaultRequest(self::PROXY_ACTION_LIST_CHART)
				->setParams($requestParams)
				->perform()
		;

		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		return $response->setData($resultData ?? []);
	}

	/**
	 * @inheritDoc
	 */
	public function getChartById(int $id): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_GET_CHART)
				->setParams(['id' => $id])
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function createChart(array $payload): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_CREATE_CHART)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams(['payload' => $payload])
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function updateChart(int $id, array $fields): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_UPDATE_CHART)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams([
					'id' => $id,
					'fields' => $fields,
				])
				->perform()
			;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteChart(int $id): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		return
			$this
				->createDefaultRequest(self::PROXY_ACTION_DELETE_CHART)
				->removeBefore(Middleware\ReadyGate::getMiddlewareId())
				->removeAfter(Middleware\StatusArbiter::getMiddlewareId())
				->setParams(['id' => $id])
				->perform()
			;
	}

	// endregion

	// region Self-hosted license

	/**
	 * @inheritDoc
	 *
	 * A cloud portal cannot own the self-hosted license extension, so the call is refused locally and no
	 * request is sent to the proxy.
	 */
	public function setSelfHostedLicenseExpiration(?DateTime $date): IntegratorResponse
	{
		$response = new IntegratorResponse(status: IntegratorResponse::STATUS_INNER_ERROR);
		$response->addError(
			new Error(
				'Self-hosted license expiration is not applicable to a cloud portal.',
				'selfhosted_license_not_applicable',
			)
		);

		return $response;
	}

	public function setBoxLicenseExpiration(?DateTime $date): IntegratorResponse
	{
		$response = new IntegratorResponse(status: IntegratorResponse::STATUS_INNER_ERROR);
		$response->addError(
			new Error(
				'Box license expiration is not applicable to a cloud portal.',
				'box_license_not_applicable',
			)
		);

		return $response;
	}

	/**
	 * A cloud portal has no local mode to allow or refuse, so the call is refused locally and no request is sent
	 * to the proxy.
	 */
	public function setSelfHostedEditionVerdict(bool $isAllowed): IntegratorResponse
	{
		$response = new IntegratorResponse(status: IntegratorResponse::STATUS_INNER_ERROR);
		$response->addError(
			new Error(
				'Self-hosted edition verdict is not applicable to a cloud portal.',
				'selfhosted_edition_verdict_not_applicable',
			)
		);

		return $response;
	}

	/**
	 * @inheritDoc
	 *
	 * A cloud portal has no instance of its own to reset, so the call is refused locally and no request is sent to
	 * the proxy. The refusal is what keeps the reset from being asked after the mode has changed: at that moment the
	 * factory answers with this integrator, and a refusal reads as "not reset".
	 */
	public function resetSelfHostedLicense(): IntegratorResponse
	{
		$response = new IntegratorResponse(status: IntegratorResponse::STATUS_INNER_ERROR);
		$response->addError(
			new Error(
				'Self-hosted license reset is not applicable to a cloud portal.',
				'selfhosted_license_reset_not_applicable',
			)
		);

		return $response;
	}

	// endregion

	private function decode(string $data)
	{
		try
		{
			return Json::decode($data);
		}
		catch (ArgumentException $e)
		{
			return null;
		}
	}

	private static function normalizeExpirationTimestamp(?Date $date): int
	{
		return $date?->getTimestamp() ?? 0;
	}

	private function isOwnerFilterApplied(IntegratorResponse $response): bool
	{
		$data = $response->getData();

		return is_array($data) && ($data['owner_filter_applied'] ?? null) === true;
	}

	/**
	 * Parses proxy response with dashboard list into DashboardList DTO.
	 *
	 * @return IntegratorResponse<Dto\DashboardList>
	 */
	private function parseDashboardListResponse(IntegratorResponse $response): IntegratorResponse
	{
		if ($response->hasErrors())
		{
			return $response;
		}

		$resultData = $response->getData();

		$innerDashboards = $resultData['dashboards'] ?? [];
		$commonCount = $resultData['common_count'] ?? 0;
		$dashboards = [];

		foreach ($innerDashboards as $dashboardData)
		{
			$jsonMetadata = $this->decode($dashboardData['json_metadata']) ?? [];
			$dateModify = null;
			if (isset($dashboardData['timestamp_modify']))
			{
				$dateModify = DateTime::createFromTimestamp((int)$dashboardData['timestamp_modify']);
			}

			$dashboards[] = new Dto\Dashboard(
				id: $dashboardData['id'],
				title: $dashboardData['title'],
				url: $dashboardData['url'] ?? '',
				editUrl: $dashboardData['edit_url'] ?? '',
				isEditable: $dashboardData['is_editable'] ?? false,
				published: $dashboardData['published'] ?? true,
				nativeFilterConfig: $jsonMetadata['native_filter_configuration'] ?? [],
				dateModify: $dateModify,
				jsonMetadata: $jsonMetadata,
			);
		}

		$dashboardList = new Dto\DashboardList(
			dashboards: $dashboards,
			commonCount: $commonCount,
		);

		return $response->setData($dashboardList);
	}
}
