<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\SqlExecutionContext;
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
use Bitrix\Main\Command\AbstractCommand;
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
use Bitrix\Superset\Public\Commands\Cache\InvalidateSupersetPermissionCacheCommand;
use Bitrix\Superset\Public\Commands\Chart\CreateChartCommand;
use Bitrix\Superset\Public\Commands\Chart\DeleteChartsCommand;
use Bitrix\Superset\Public\Commands\Chart\UpdateChartCommand;
use Bitrix\Superset\Public\Commands\Dashboard\CopyDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\CreateEmptyDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\DeleteDashboardsCommand;
use Bitrix\Superset\Public\Commands\Dashboard\ExportDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\ImportDashboardCommand;
use Bitrix\Superset\Public\Commands\Dashboard\SetDashboardOwnerCommand;
use Bitrix\Superset\Public\Commands\Dashboard\UpdateDashboardCommand;
use Bitrix\Superset\Public\Commands\Database\ChangeDatabaseTokenCommand;
use Bitrix\Superset\Public\Commands\Dataset\CreateDatasetCommand;
use Bitrix\Superset\Public\Commands\Dataset\CreateDatasetFromSqlCommand;
use Bitrix\Superset\Public\Commands\Dataset\CreateVirtualDatasetCommand;
use Bitrix\Superset\Public\Commands\Dataset\DeleteDatasetCommand;
use Bitrix\Superset\Public\Commands\Dataset\InitRequiredDatasetCommand;
use Bitrix\Superset\Public\Commands\License\ResetSelfHostedLicenseCommand;
use Bitrix\Superset\Public\Commands\License\SetBoxLicenseExpirationCommand;
use Bitrix\Superset\Public\Commands\License\SetSelfHostedEditionVerdictCommand;
use Bitrix\Superset\Public\Commands\License\SetSelfHostedLicenseExpirationCommand;
use Bitrix\Superset\Public\Commands\Server\SetServerTimezoneCommand;
use Bitrix\Superset\Public\Commands\SqlLab\ExecuteSqlCommand;
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
use Bitrix\Superset\Public\Providers\TableProvider;
use Bitrix\Superset\Public\Providers\UnusedElementsProvider;
use Bitrix\Superset\Public\Providers\UserProvider;

/**
 * Integrator implementation for self-hosted Superset.
 * Delegates Superset API calls to the local boxed module instead of the cloud proxy.
 *
 * Infrastructure methods (register, freeze, etc.) are no-op since the client manages the instance.
 */
class SelfHostedIntegrator implements IntegratorInterface, DashboardSourceReaderInterface
{
	/**
	 * Timeouts of the delivery of a license term, short on purpose: a term is one date written by a background pass,
	 * while the defaults of the connector are meant for requests that carry data. The stream bound limits a stalled
	 * answer and not a slow one, so an instance that is merely busy is not cut off.
	 */
	private const LICENSE_DELIVERY_SOCKET_TIMEOUT = 5;
	private const LICENSE_DELIVERY_STREAM_TIMEOUT = 15;

	/**
	 * Timeouts of the permission cache reset. The connection bound is short - the reset carries no data and is
	 * sent from the background job of an ordinary hit, so a person saving a role or a role relation waits out
	 * whatever this call waits out, and an instance that does not answer at all has to cost them seconds.
	 *
	 * The answer bound is deliberately NOT short, and this is the non-obvious half. A slow but successful reset
	 * must not be written down as a failure: the mark then stays pending and the tail agent re-sends it every
	 * retry window for as long as the scan stays slow, so the cost of giving up too early is paid over and over.
	 *
	 * No budget is promised on the other side of this branch: the point of the instance bounds nothing for us.
	 * The value is therefore ours on both branches and kept equal on both: what it bounds is the wait of the
	 * person who saved the rights, and that wait must not depend on where the portal happens to live.
	 */
	private const PERMISSION_CACHE_RESET_SOCKET_TIMEOUT = 5;
	private const PERMISSION_CACHE_RESET_STREAM_TIMEOUT = 30;

	/**
	 * The build of the instance that first answers /api/v1/bitrix_cache/clear_permissions.
	 *
	 * Below it the route does not exist, and the call has to be refused here rather than sent: what an
	 * instance without the route answers is not told apart from a reset that deleted nothing, so an old
	 * box would look like a portal whose rights are being reset - while none of them ever were.
	 *
	 * A placeholder until that build is stamped: the check is wired, only the number moves. Note that
	 * the version of the instance is kept for a day, so an upgraded box keeps being refused until the
	 * kept value expires or SelfHostedConnectionService::getSupersetVersion(true) drops it.
	 */
	private const PERMISSION_CACHE_RESET_REQUIRED_VERSION = '6.0.0_bx1000';
	/**
	 * First Superset build that runs a read as the person named by the portal (X-Bx-Act-As). An older box
	 * would refuse it with no reason the agent can act on, so the read is refused up front with a reason
	 * telling the administrator to update. The version of the instance is kept for a day, as for
	 * PERMISSION_CACHE_RESET_REQUIRED_VERSION.
	 */
	private const ACT_AS_REQUIRED_VERSION = '6.0.0_bx1100';

	/**
	 * A chart or dashboard hidden from the person by the table permissions answers 404, a query Trino refused
	 * answers 422: a refusal of the request, not a failure of the service.
	 */
	private const REFUSAL_STATUSES = [
		IntegratorResponse::STATUS_NOT_FOUND,
		IntegratorResponse::STATUS_UNPROCESSABLE_ENTITY,
	];

	static private ?self $instance = null;

	private IntegratorLogger $logger;
	private SelfHostedConnectionService $connectionService;
	private ?ServerReferenceDto $server = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Drops the remembered integrator so that the record of the connected server is looked up again: the record is
	 * read once, when the integrator is built, and an instance that outlived a change of it answers by a reference
	 * to a row that may no longer be there. Useful for testing.
	 */
	public static function reset(): void
	{
		self::$instance = null;
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

	protected function runSupersetCommand(AbstractCommand $command): Result
	{
		return $command->run();
	}

	protected function createDefaultRequest(string $action): IntegratorRequest
	{
		return (new IntegratorRequest())
			->setAction($action)
			->addBefore(new Middleware\SelfHostedLicenseRestriction())
			->addBefore(new Middleware\TariffRestriction())
			->addBefore(new Middleware\UserAccess())
			->addBefore(new Middleware\RequiredDatasetSync())
			->addAfter(new Middleware\Logger($this->logger, self::REFUSAL_STATUSES))
		;
	}

	/**
	 * Whether a read of the AI tools runs in Superset as the person it is made for. Only the table permission
	 * axis needs it; without the axis the read goes under the service account, as before act-as, and the
	 * version of the box is not asked. Decided on every call, nothing is kept.
	 */
	protected function isActAsEnabled(): bool
	{
		return Feature::isTablePermissionsEnabled();
	}

	/**
	 * A read on behalf of a person: refused on a box older than {@see self::ACT_AS_REQUIRED_VERSION}.
	 * Without act-as, or without a server to ask the version of, the request is the default one.
	 */
	private function createActAsRequest(string $action, bool $actAs): IntegratorRequest
	{
		$request = $this->createDefaultRequest($action);
		$server = $actAs ? $this->getServer() : null;
		if ($server !== null)
		{
			$request->addBefore(new Middleware\SupersetVersionCheck($server, self::ACT_AS_REQUIRED_VERSION));
		}

		return $request;
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
		$ownedByRequestUser = !empty($filter['ownedByRequestUser']);

		$handler = function (IntegratorRequest $request) use ($ids, $neqIds, $ownedByRequestUser): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$provider = new DashboardProvider($server);
			if ($ownedByRequestUser)
			{
				if (!method_exists($provider, 'listOwnedBy'))
				{
					return new IntegratorResponse(
						status: IntegratorResponse::STATUS_NO_ACCESS,
						errors: [new Error('Installed superset module cannot filter dashboards by owner')],
					);
				}

				$ownerId = $this->resolveRequestUserExternalId($request, $server);
				if ($ownerId === null)
				{
					return new IntegratorResponse(
						status: IntegratorResponse::STATUS_NO_ACCESS,
						errors: [new Error('Superset user of the request was not resolved')],
					);
				}

				$result = $provider->listOwnedBy($ownerId, $ids, $neqIds);
			}
			else
			{
				$result = $provider->list($ids, $neqIds);
			}

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

		$requestParams = [];
		if ($ownedByRequestUser)
		{
			$requestParams['ownedByRequestUser'] = true;
		}

		return $this
			->createDefaultRequest('selfhosted/dashboard/listByFilter')
			->setParams($requestParams)
			->setHandler($handler)
			->perform()
		;
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
	public function getDashboardSourceById(int $dashboardId): IntegratorResponse
	{
		$handler = function () use ($dashboardId): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$provider = new DashboardProvider($server);
			$result = $provider->getById($dashboardId);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$dashboardData = $result->getData()['dashboard'] ?? null;
			if (!$dashboardData)
			{
				return $this->buildDataResponse(null);
			}

			if (!method_exists($provider, 'getChartIds'))
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Installed superset module cannot read dashboard chart membership')),
				);
			}

			try
			{
				$membershipResult = $provider->getChartIds($dashboardId);
			}
			catch (\Throwable)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Dashboard chart membership is unavailable')),
				);
			}

			if (!$membershipResult->isSuccess())
			{
				return $this->buildErrorResponse($membershipResult);
			}

			$membershipData = $membershipResult->getData();
			if (
				!array_key_exists('chartIds', $membershipData)
				|| !Dto\DashboardSource::isValidChartIds($membershipData['chartIds'])
			)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error('Invalid dashboard chart membership')),
				);
			}

			return $this->buildDataResponse($this->mapDashboardSourceDto($dashboardData, $membershipData['chartIds']));
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
	public function getDashboardEmbeddedCredentials(
		int $dashboardId,
		array $rlsRules = [],
		int $expSeconds = 0,
		?Dto\User $user = null,
	): IntegratorResponse
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
			->setUser($user)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDemoEmbeddedCredentials(string $dashboardCode, string $lang): IntegratorResponse
	{
		$result = (new Result())->addError(new Error('Demo dashboards are not supported in self-hosted mode'));
		// Answered without a request, so the Logger middleware does not journal it.
		$this->logger->logErrors($result->getErrors());

		return $this->buildErrorResponse($result);
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
		?User $user,
		int $dashboardId,
		array $appliedFilters = [],
		array $urlParams = [],
		int $streamTimeout = 60 * 5,
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
		bool $arbitrateServiceFailure = true,
	): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use (
			$dashboardId,
			$appliedFilters,
			$urlParams,
			$streamTimeout,
			$chartIds,
			$sort,
			$limit,
			$offset,
			$actAs,
		): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
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
				$supersetUser,
			);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/dashboard/getOverview', $actAs)
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getFilterValues(
		?User $user,
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

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use (
			$dashboardId,
			$filterColumn,
			$appliedFilters,
			$urlParams,
			$search,
			$limit,
			$streamTimeout,
			$actAs,
		): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new DashboardProvider($server))->getFilterValues(
				$dashboardId,
				$filterColumn,
				$appliedFilters,
				$urlParams,
				$search,
				$limit,
				$streamTimeout,
				$supersetUser,
			);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/dashboard/getFilterValues', $actAs)
			->setUser($user)
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

	// region SQL Lab

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

		// The person is required in either mode: they key the limiter and the audit.
		$actAs = $context !== null && $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use ($sql, $limit, $actAs): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$user = $request->getUser();
			$supersetUser = $user === null ? null : $this->getSupersetUser($server, $user->id);
			if ($supersetUser === null)
			{
				return $this->buildErrorResponse(
					(new Result())->addError(new Error(
						'Current superset user not found',
						IntegratorResponse::STATUS_NO_ACCESS,
					)),
				);
			}

			$result = $this->runSupersetCommand(new ExecuteSqlCommand($server, $sql, $limit, $supersetUser, $actAs));
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		$request = $this->createActAsRequest('selfhosted/sqllab/execute', $actAs);
		if ($context !== null)
		{
			$request->setUser($context->user);
		}

		return $request
			->setHandler($handler)
			->perform()
		;
	}

	// endregion

	// region Table

	/**
	 * @inheritDoc
	 */
	public function listTables(?User $user, ?string $schema = null): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use ($schema, $actAs): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new TableProvider($server))->list($schema, $supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/table/list', $actAs)
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getTableMetadata(?User $user, string $tableName, ?string $schema = null): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use ($tableName, $schema, $actAs): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new TableProvider($server))->getMetadata($tableName, $schema, $supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/table/getMetadata', $actAs)
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
	public function getPhysicalDatasetList(): IntegratorResponse
	{
		$handler = function (): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DatasetProvider($server))->listPhysical();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['datasets'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/dataset/listPhysical')
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getDatasetById(int $id, ?User $user = null): IntegratorResponse
	{
		$actAs = $user !== null && $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use ($id, $actAs): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new DatasetProvider($server))->getById($id, $supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			// Return the dataset unwrapped ({id, table_name, owners, ...}) to match
			// the cloud proxy (SupersetProxy Controller\Dataset::getAction) and
			// getDatasetByName() below. DatasetProvider::getById() wraps it in
			// ['dataset' => ...]; without this unwrap box callers read $data['id']
			// off the wrapper and never find the dataset.
			return $this->buildDataResponse($result->getData()['dataset'] ?? []);
		};

		return $this
			->createActAsRequest('selfhosted/dataset/get', $actAs)
			->setUser($user)
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
	public function listDatasets(
		?User $user,
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

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use (
			$ids,
			$neqIds,
			$page,
			$pageSize,
			$actAs,
		): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new DatasetProvider($server))->list($ids, $neqIds, $page, $pageSize, $supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			// Bounded mode keeps the full {datasets, count} envelope; legacy mode
			// returns a flat array for callers that iterate the list directly.
			if ($pageSize !== null)
			{
				return $this->buildDataResponse(is_array($data) ? $data : []);
			}

			return $this->buildDataResponse($data['datasets'] ?? []);
		};

		return $this
			->createActAsRequest('selfhosted/dataset/list', $actAs)
			->setUser($user)
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
	public function createVirtualDataset(
		User $user,
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

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use (
			$datasetName,
			$sourceTable,
			$columns,
			$sourceSchema,
			$actAs,
		): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			// The dataset is owned by the employee; it is created as them only while the axis is on.
			$supersetUser = $this->getRequestSupersetUser($server, $request);
			if ($supersetUser === null || $supersetUser->getExternalId() <= 0)
			{
				return $this->buildSupersetUserNotFoundResponse();
			}
			$ownerId = $supersetUser->getExternalId();

			$result = (new CreateVirtualDatasetCommand(
				$server,
				$datasetName,
				$sourceTable,
				$columns,
				$ownerId,
				$sourceSchema,
				$actAs ? $supersetUser : null,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/dataset/createVirtual', $actAs)
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function createDatasetFromSql(User $user, string $datasetName, string $sql): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use ($datasetName, $sql, $actAs): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			// The dataset is owned by the employee; it is created as them only while the axis is on.
			$supersetUser = $this->getRequestSupersetUser($server, $request);
			if ($supersetUser === null || $supersetUser->getExternalId() <= 0)
			{
				return $this->buildSupersetUserNotFoundResponse();
			}
			$ownerId = $supersetUser->getExternalId();

			$result = (new CreateDatasetFromSqlCommand(
				$server,
				$datasetName,
				$sql,
				$ownerId,
				$actAs ? $supersetUser : null,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/dataset/createFromSql', $actAs)
			->setUser($user)
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

	// region Chart

	/**
	 * @inheritDoc
	 */
	public function listCharts(
		?User $user,
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

		$actAs = $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use (
			$ids,
			$nameFilter,
			$page,
			$pageSize,
			$actAs,
		): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new ChartProvider($server))->list($ids, $nameFilter, $page, $pageSize, $supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createActAsRequest('selfhosted/chart/list', $actAs)
			->setUser($user)
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function getChartById(int $id, ?User $user = null): IntegratorResponse
	{
		if (!self::isAiToolsFeatureEnabled())
		{
			return self::aiToolsDisabledResponse();
		}

		$actAs = $user !== null && $this->isActAsEnabled();
		$handler = function (IntegratorRequest $request) use ($id, $actAs): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$supersetUser = null;
			if ($actAs)
			{
				$supersetUser = $this->getRequestSupersetUser($server, $request);
				if ($supersetUser === null)
				{
					return $this->buildSupersetUserNotFoundResponse();
				}
			}

			$result = (new ChartProvider($server))->getById($id, $supersetUser);
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['chart'] ?? []);
		};

		return $this
			->createActAsRequest('selfhosted/chart/get', $actAs)
			->setUser($user)
			->setHandler($handler)
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

		$handler = function () use ($payload): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new CreateChartCommand($server, $payload))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['chart'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/chart/create')
			->setHandler($handler)
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

		$handler = function () use ($id, $fields): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new UpdateChartCommand($server, $id, $fields))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			$data = $result->getData();

			return $this->buildDataResponse($data['chart'] ?? []);
		};

		return $this
			->createDefaultRequest('selfhosted/chart/update')
			->setHandler($handler)
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

		$handler = function () use ($id): IntegratorResponse {
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new DeleteChartsCommand($server, [$id]))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/chart/delete')
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
	public function clearPermissionCache(): IntegratorResponse
	{
		$handler = function (): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new InvalidateSupersetPermissionCacheCommand(
				$server,
				self::PERMISSION_CACHE_RESET_SOCKET_TIMEOUT,
				self::PERMISSION_CACHE_RESET_STREAM_TIMEOUT,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		$request = $this
			->createDefaultRequest('selfhosted/cache/clearPermissions')
			->setHandler($handler)
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
		;

		$server = $this->getServer();
		if ($server !== null)
		{
			// The version is asked of the instance, so the check is added only when there is one to ask.
			// Without a server the handler answers "not configured", and that is the more precise of the two.
			$request->addBefore(
				new Middleware\SupersetVersionCheck($server, self::PERMISSION_CACHE_RESET_REQUIRED_VERSION)
			);
		}

		return $request->perform();
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

	// region Self-hosted license

	/**
	 * @inheritDoc
	 */
	public function setSelfHostedLicenseExpiration(?DateTime $date): IntegratorResponse
	{
		$handler = function () use ($date): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new SetSelfHostedLicenseExpirationCommand(
				$server,
				$date?->getTimestamp(),
				self::LICENSE_DELIVERY_SOCKET_TIMEOUT,
				self::LICENSE_DELIVERY_STREAM_TIMEOUT,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/license/expiration')
			// The one call that must survive an inactive license: an instance learns that the term is over from
			// this very request, and learns about a renewal the same way. Blocking it would leave the instance
			// working on the term it still holds and, after a renewal, blocked with nothing able to unblock it.
			->removeBefore(Middleware\SelfHostedLicenseRestriction::getMiddlewareId())
			// Handing over a number needs neither the catalog of the tables nor a provisioned user of the instance,
			// and this request is sent by an agent: the catalog alone costs tens of queries and its own request,
			// and the user costs up to four more against an instance that may well be unreachable.
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	public function setBoxLicenseExpiration(?DateTime $date): IntegratorResponse
	{
		$handler = function () use ($date): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new SetBoxLicenseExpirationCommand(
				$server,
				$date?->getTimestamp(),
				self::LICENSE_DELIVERY_SOCKET_TIMEOUT,
				self::LICENSE_DELIVERY_STREAM_TIMEOUT,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/license/box_expiration')
			// Excluded from the license restriction for the same reason as the term of the extension: this request
			// is how a blocked instance learns that the license of the product is renewed.
			->removeBefore(Middleware\SelfHostedLicenseRestriction::getMiddlewareId())
			// The catalog of the tables and the user of the instance are dropped for the same reason: this term is
			// a number too, and the pass of the agent is its only occasion.
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	public function setSelfHostedEditionVerdict(bool $isAllowed): IntegratorResponse
	{
		$handler = function () use ($isAllowed): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new SetSelfHostedEditionVerdictCommand(
				$server,
				$isAllowed,
				self::LICENSE_DELIVERY_SOCKET_TIMEOUT,
				self::LICENSE_DELIVERY_STREAM_TIMEOUT,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/license/edition')
			// Excluded from the license restriction for the same reason as the terms: an instance closed by this
			// verdict learns from this very request that the edition is back, and nothing else could open it.
			->removeBefore(Middleware\SelfHostedLicenseRestriction::getMiddlewareId())
			// The catalog of the tables and the user of the instance are dropped for the same reason: the request
			// carries one boolean and its only occasion is the pass of the agent.
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
			->setHandler($handler)
			->perform()
		;
	}

	/**
	 * @inheritDoc
	 */
	public function resetSelfHostedLicense(): IntegratorResponse
	{
		$handler = function (): IntegratorResponse
		{
			$server = $this->getServer();
			if ($server === null)
			{
				return $this->buildServerNotConfiguredResponse();
			}

			$result = (new ResetSelfHostedLicenseCommand(
				$server,
				self::LICENSE_DELIVERY_SOCKET_TIMEOUT,
				self::LICENSE_DELIVERY_STREAM_TIMEOUT,
			))->run();
			if (!$result->isSuccess())
			{
				return $this->buildErrorResponse($result);
			}

			return $this->buildDataResponse($result->getData());
		};

		return $this
			->createDefaultRequest('selfhosted/license/reset')
			// The reset is asked of an instance that is blocked more often than not - a term that is over is the
			// usual reason to leave the local mode - so the restriction of the license must not stop it.
			->removeBefore(Middleware\SelfHostedLicenseRestriction::getMiddlewareId())
			// Neither the catalog of the tables nor a provisioned user has anything to do with wiping two numbers,
			// and the caller of this one is an administrator waiting for the page.
			->removeBefore(Middleware\RequiredDatasetSync::getMiddlewareId())
			->removeBefore(Middleware\UserAccess::getMiddlewareId())
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
		$declaredStatus = $result->getData()['httpStatus'] ?? null;
		if (is_int($declaredStatus) && $declaredStatus >= 100 && $declaredStatus <= 599)
		{
			// A symbolic error code carries no status, so a facade states it next to the code:
			// without it a query-level failure would answer 500 and StatusArbiter would move the
			// whole portal to SUPERSET_STATUS_ERROR.
			$errorCode = $declaredStatus;
		}
		elseif (!empty($errors))
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
		$externalId = $this->getSupersetUser($server, $bitrixUserId)?->getExternalId();

		return ($externalId !== null && $externalId > 0) ? $externalId : null;
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
		$externalId = $user !== null
			? $this->getSupersetUser($server, $user->id)?->getExternalId()
			: $this->getCurrentUserExternalId($server)
		;

		return ($externalId !== null && $externalId > 0) ? $externalId : null;
	}

	private function getRequestSupersetUser(
		ServerReferenceDto $server,
		IntegratorRequest $request,
	): ?SupersetUserReferenceDto
	{
		$user = $request->getUser();

		return $user === null ? null : $this->getSupersetUser($server, $user->id);
	}

	private function buildSupersetUserNotFoundResponse(): IntegratorResponse
	{
		return $this->buildErrorResponse(
			(new Result())->addError(new Error(
				'Current superset user not found',
				IntegratorResponse::STATUS_NO_ACCESS,
			)),
		);
	}

	protected function getSupersetUser(ServerReferenceDto $server, int $bitrixUserId): ?SupersetUserReferenceDto
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

	private function mapDashboardSourceDto(array $data, array $chartIds): Dto\DashboardSource
	{
		$metadata = $data['json_metadata'] ?? null;

		return new Dto\DashboardSource(
			positionJson: $data['position_json'] ?? null,
			jsonMetadataSource: is_array($metadata) ? Json::encode($metadata) : $metadata,
			published: $data['published'] ?? true,
			chartIds: $chartIds,
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
			jsonMetadata: $jsonMetadata,
			positionJson: $data['position_json'] ?? null,
			jsonMetadataSource: is_array($rawMetadata) ? Json::encode($rawMetadata) : ($data['json_metadata'] ?? null),
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
