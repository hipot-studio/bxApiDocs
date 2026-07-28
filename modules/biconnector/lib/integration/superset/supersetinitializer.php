<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Superset\ActionFilter\ProxyAuth;
use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Source\Csv;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Rest\AppTable;

final class SupersetInitializer
{
	public const SUPERSET_STATUS_DOESNT_EXISTS = 'DOESNT_EXISTS';
	public const SUPERSET_STATUS_LOAD = 'LOAD';
	public const SUPERSET_STATUS_READY = 'READY';
	public const SUPERSET_STATUS_ERROR = 'ERROR';
	public const SUPERSET_STATUS_LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';
	public const SUPERSET_STATUS_DELETED = 'DELETED';
	public const SUPERSET_STATUS_PENDING_DELETE = 'PENDING_DELETE';
	public const SUPERSET_STATUS_PENDING_DELETE_SUSPENDED = 'PENDING_DELETE_SUSPENDED';

	public const FREEZE_REASON_TARIFF = 'TARIFF';
	public const FREEZE_REASON_PENDING_DELETE = 'PENDING_DELETE';

	public const ENABLE_MODE_RESTORE = 'restore';
	public const ENABLE_MODE_RESET = 'reset';

	public const ERROR_DELETE_INSTANCE_OPTION = 'error_superset_delete_instance';
	public const EVENT_ON_AFTER_SUPERSET_STATUS_CHANGE = 'onAfterSupersetStatusChange';

	private const SUPERSET_CLEAN_TIMESTAMP_OPTION = 'superset_clean_timestamp';
	private const SUPERSET_CLEAN_TIMEOUT_OPTION = 'superset_clean_timeout';
	private const SUPERSET_PENDING_DELETE_TIMEOUT_OPTION = 'superset_pending_delete_timeout';
	private const SUPERSET_PENDING_DELETE_DAYS = 30;
	private const SUPERSET_PENDING_DELETE_NOTIFY_OPTION = 'superset_pending_delete_notify_timeout';
	private const SUPERSET_PENDING_DELETE_NOTIFY_DAYS = 1;

	private const SUPERSET_CREATED_BY_OPTION = 'superset_created_by_user';
	private const SUPERSET_INITIAL_DASHBOARD_OPTION = 'superset_initial_dashboard';

	private const REFRESH_DOMAIN_RETRY_OPTION = '~superset_refresh_domain_retry_scheduled';

	private const SUPERSET_INSTANCE_SUSPENDED_OPTION = '~superset_instance_suspended';

	private const SUPERSET_INSTANCE_EXISTS_OPTION = '~superset_instance_exists';

	/**
	 * Container for superset status. Used for tests mocking
	 *
	 * @var SupersetStatusOptionContainer
	 */
	private static SupersetStatusOptionContainer $statusContainer;

	/**
	 * @return string current superset status
	 */
	public static function startupSuperset(): string
	{
		$status = self::getSupersetStatus();
		SupersetInitializerLogger::logInfo('Portal make superset startup', ['current_status' => $status]);

		if (
			Loader::includeModule('bitrix24')
			&& !Feature::isFeatureEnabledFor('bi_constructor', \CBitrix24::getLicenseType())
		)
		{
			SupersetInitializerLogger::logInfo('Skip superset startup: bi_constructor feature is disabled by current tariff');

			return $status;
		}

		$newStatus = self::startSupersetInitialize();
		if (self::getSupersetStatus() !== $newStatus)
		{
			self::setSupersetStatus($newStatus);
		}

		return $status;
	}

	public static function initializeOrCheckSupersetStatus(): string
	{
		$status = self::getSupersetStatus();

		$touchStatuses = [
			self::SUPERSET_STATUS_ERROR,
			self::SUPERSET_STATUS_LOAD,
		];

		if (!in_array($status, $touchStatuses))
		{
			return $status;
		}

		return self::startupSuperset();
	}

	/**
	 * @return string status of superset after startup. If request makes in background, return LOAD status
	 */
	private static function startSupersetInitialize(): string
	{
		if (self::getSupersetStatus() === self::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			if (SupersetHostMode::isSelfHosted())
			{
				return self::makeSupersetCreateRequest();
			}

			Application::getInstance()->addBackgroundJob(static function () {
				self::makeSupersetCreateRequest();
			});

			return self::SUPERSET_STATUS_LOAD;
		}

		return self::makeSupersetCreateRequest();
	}

	private static function getOrCreateAccessKey(): Result
	{
		$result = new Result();
		$user = \Bitrix\Main\Engine\CurrentUser::get();
		$accessKey = KeyManager::getAccessKey();
		if ($accessKey !== null)
		{
			$result->setData([
				'ACCESS_KEY' => $accessKey,
			]);
		}
		else
		{
			$createdResult = KeyManager::createAccessKey($user);
			if (!$createdResult->isSuccess())
			{
				$createdResult->addError(new Error('Cannot create access key'));
			}

			$result = $createdResult;
		}

		return $result;
	}

	/**
	 * @param string $supersetAddress Address of enabled superset. Used for logs. Not required
	 * @return void
	 */
	public static function enableSuperset(string $supersetAddress = ''): void
	{
		if (self::getSupersetStatus() === self::SUPERSET_STATUS_READY)
		{
			return;
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_READY);
		self::markSupersetInstanceExists(true);
		self::markSupersetInstanceSuspended(false);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_READY);

		DashboardManager::notifySupersetCreated(
			(int)Option::get('biconnector', self::SUPERSET_CREATED_BY_OPTION),
			(int)Option::get('biconnector', self::SUPERSET_INITIAL_DASHBOARD_OPTION),
		);
		Option::delete('biconnector', ['name' => self::SUPERSET_CREATED_BY_OPTION]);
		Option::delete('biconnector', ['name' => self::SUPERSET_INITIAL_DASHBOARD_OPTION]);

		$logParams = [];
		if (!empty($supersetAddress))
		{
			$logParams['superset_address'] = $supersetAddress;
		}
		SupersetInitializerLogger::logInfo('Superset successfully started', $logParams);
	}

	public static function freezeSuperset(array $params = []): void
	{
		$proxyIntegrator = IntegratorFactory::getInstance();
		$proxyIntegrator->freezeSuperset($params);
	}

	public static function unfreezeSuperset(array $params = []): IntegratorResponse
	{
		$proxyIntegrator = IntegratorFactory::getInstance();

		return $proxyIntegrator->unfreezeSuperset($params);
	}

	public static function suspendSuperset(array $params = []): void
	{
		$proxyIntegrator = IntegratorFactory::getInstance();
		$proxyIntegrator->suspendSuperset($params);
	}

	public static function resumeSuperset(array $params = []): IntegratorResponse
	{
		$proxyIntegrator = IntegratorFactory::getInstance();

		return $proxyIntegrator->resumeSuperset($params);
	}

	public static function markSupersetInstanceSuspended(bool $value): void
	{
		if ($value)
		{
			Option::set('biconnector', self::SUPERSET_INSTANCE_SUSPENDED_OPTION, 'Y');
		}
		else
		{
			Option::delete('biconnector', ['name' => self::SUPERSET_INSTANCE_SUSPENDED_OPTION]);
		}
	}

	public static function isSupersetInstanceSuspended(): bool
	{
		return Option::get('biconnector', self::SUPERSET_INSTANCE_SUSPENDED_OPTION, 'N') === 'Y';
	}

	public static function markSupersetInstanceExists(bool $value): void
	{
		if ($value)
		{
			Option::set('biconnector', self::SUPERSET_INSTANCE_EXISTS_OPTION, 'Y');
		}
		else
		{
			Option::delete('biconnector', ['name' => self::SUPERSET_INSTANCE_EXISTS_OPTION]);
		}
	}

	public static function isSupersetInstanceExists(): bool
	{
		return Option::get('biconnector', self::SUPERSET_INSTANCE_EXISTS_OPTION, 'N') === 'Y';
	}

	public static function setSupersetStatus(string $status): void
	{
		$oldStatus = self::getSupersetStatus();

		SupersetInitializerLogger::logInfo('Superset status changed to ' . $status);
		if (!isset(self::$statusContainer))
		{
			self::$statusContainer = new SupersetStatusOptionContainer();
		}

		self::$statusContainer->set($status);

		if ($oldStatus !== $status)
		{
			(new Event(
				'biconnector',
				self::EVENT_ON_AFTER_SUPERSET_STATUS_CHANGE,
				[
					'oldStatus' => $oldStatus,
					'status' => $status,
				]
			))->send();
		}
	}

	public static function getSupersetStatus(): string
	{
		if (!isset(self::$statusContainer))
		{
			self::$statusContainer = new SupersetStatusOptionContainer();
		}

		return self::$statusContainer->get();
	}

	public static function setSupersetStatusContainer(SupersetStatusOptionContainer $container)
	{
		self::$statusContainer = $container;
	}

	/**
	 * @return string status of superset after startup. If request makes in background, return LOAD status
	 */
	private static function makeSupersetCreateRequest(): string
	{
		$integrator = IntegratorFactory::getInstance();

		$getKeyResult = self::getOrCreateAccessKey();
		if (!$getKeyResult->isSuccess())
		{
			SupersetInitializerLogger::logErrors($getKeyResult->getErrors());

			return self::SUPERSET_STATUS_ERROR;
		}

		$accessKey = $getKeyResult->getData()['ACCESS_KEY'];
		$response = $integrator->startSuperset($accessKey);

		$responseStatus = $response->getStatus();

		$status = self::SUPERSET_STATUS_LOAD;
		if ($responseStatus === IntegratorResponse::STATUS_CREATED)
		{
			$responseData = $response->getData();
			self::enableSuperset($responseData['superset_address'] ?? '');
			$status = self::SUPERSET_STATUS_READY;
		}
		else if ($response->hasErrors())
		{
			if ($responseStatus === IntegratorResponse::STATUS_LIMIT_EXCEEDED)
			{
				self::onLimitExceeded(...$response->getErrors());
				$status = self::SUPERSET_STATUS_LIMIT_EXCEEDED;
			}
			elseif (self::isInstanceUnavailableStartupStatus($responseStatus))
			{
				// A deactivated/unreachable instance can't finish startup yet: the proxy reports it as a
				// frozen instance (DEACTIVATED_INSTANCE) or a gateway error (502/503/504, sometimes as an
				// unparsable Bad Gateway page). Keep LOAD — the portal is waiting for reactivation —
				// instead of flipping to ERROR and re-registering. Mirrors the data-path FROZEN→LOAD
				// mapping done by StatusArbiter.
				SupersetInitializerLogger::logInfo(
					'Superset instance unavailable on startup, keep waiting',
					['response_status' => $responseStatus],
				);
				$status = self::SUPERSET_STATUS_LOAD;
			}
			else
			{
				self::onUnsuccessfulSupersetStartup(...$response->getErrors());
				$status = self::SUPERSET_STATUS_ERROR;
			}
		}

		$responseData = $response->getData();
		if (isset($responseData['token']))
		{
			Option::set('biconnector', ProxyAuth::SUPERSET_PROXY_TOKEN_OPTION, $responseData['token']);
		}

		return $status;
	}

	/**
	 * Startup response statuses that mean "instance is not reachable yet" rather than a genuine failure:
	 * a deactivated (frozen) instance or a gateway-level error. Such a startup must keep the portal in
	 * LOAD (waiting for reactivation), not flip it to ERROR.
	 */
	private static function isInstanceUnavailableStartupStatus(int $responseStatus): bool
	{
		return $responseStatus === IntegratorResponse::STATUS_FROZEN // 555 - deactivated instance
			|| $responseStatus === 502 // Bad Gateway
			|| $responseStatus === 503 // Service Unavailable
			|| $responseStatus === 504 // Gateway Timeout
		;
	}

	public static function isSupersetReady(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_READY;
	}

	public static function isSupersetExist(): bool
	{
		$status = self::getSupersetStatus();

		return $status !== self::SUPERSET_STATUS_DOESNT_EXISTS && $status !== self::SUPERSET_STATUS_DELETED;
	}

	public static function isSupersetDeleted(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_DELETED;
	}

	public static function isSupersetPendingDelete(): bool
	{
		$status = self::getSupersetStatus();

		return $status === self::SUPERSET_STATUS_PENDING_DELETE
			|| $status === self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED;
	}

	public static function isSupersetLoading(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_LOAD;
	}

	public static function isRebindRequired(): bool
	{
		return Option::get('biconnector', '~superset_rebind_required', 'N') === 'Y';
	}

	public static function clearRebindRequired(): void
	{
		Option::delete('biconnector', ['name' => '~superset_rebind_required']);
	}

	public static function isSupersetUnavailable(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_ERROR;
	}

	public static function onUnsuccessfulSupersetStartup(Error ...$errors): void
	{
		if (!empty($errors))
		{
			SupersetInitializerLogger::logErrors($errors, ['message' => 'error while startup superset']);
		}
		else
		{
			SupersetInitializerLogger::logErrors(
				[new Error('undefined error while startup superset')],
				['message' => 'error while startup superset']
			);
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_ERROR);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_ERROR);
	}

	public static function onLimitExceeded(Error ...$errors): void
	{
		SupersetInitializerLogger::logErrors($errors, ['message' => 'error while startup superset']);

		self::setSupersetStatus(self::SUPERSET_STATUS_LIMIT_EXCEEDED);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_LIMIT_EXCEEDED);
	}

	public static function onBitrix24LicenseChange(): void
	{
		$status = self::getSupersetStatus();
		if (
			$status === self::SUPERSET_STATUS_DOESNT_EXISTS
			|| $status === self::SUPERSET_STATUS_DELETED
		)
		{
			return;
		}

		if (Loader::includeModule('bitrix24'))
		{
			$params = [
				'reason' => self::FREEZE_REASON_TARIFF,
			];

			if (Feature::isFeatureEnabledFor('bi_constructor', \CBitrix24::getLicenseType()))
			{
				if (self::isSupersetInstanceSuspended())
				{
					self::resumeSuperset($params);
				}
				else
				{
					self::unfreezeSuperset($params);
				}
			}
			else
			{
				self::freezeSuperset($params);
			}
		}
	}

	/**
	 * Refreshes superset domain connection.
	 * Makes up to 2 attempts: immediate and via agent after 30 minutes.
	 *
	 * Allowed statuses: READY, LOAD, ERROR — superset instance exists and may become reachable.
	 * After second failure — stops. User can retry manually via "refresh encryption key".
	 *
	 * @return string|null Agent name for retry, or null to stop.
	 */
	public static function refreshSupersetDomainConnection(): ?string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$allowedStatuses = [
			self::SUPERSET_STATUS_READY,
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_ERROR,
		];

		if (!in_array(self::getSupersetStatus(), $allowedStatuses, true))
		{
			self::clearRefreshDomainRetryFlag();

			return null;
		}

		$isRetry = Option::get('biconnector', self::REFRESH_DOMAIN_RETRY_OPTION, 'N') === 'Y';

		$response = IntegratorFactory::getInstance()->refreshDomainConnection();

		if (!$response->hasErrors() && $response->getStatus() === IntegratorResponse::STATUS_OK)
		{
			self::clearRefreshDomainRetryFlag();

			return null;
		}

		// First attempt failed — schedule retry via agent
		if (!$isRetry)
		{
			Option::set('biconnector', self::REFRESH_DOMAIN_RETRY_OPTION, 'Y');

			$className = __CLASS__;
			$agentName = "\\$className::refreshSupersetDomainConnection();";

			\CAgent::AddAgent(
				$agentName,
				'biconnector',
				'N',
				3600,
				'',
				'Y',
				\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 1800, 'FULL')
			);

			return null;
		}

		// Second attempt also failed — give up
		self::clearRefreshDomainRetryFlag();

		return null;
	}

	private static function clearRefreshDomainRetryFlag(): void
	{
		Option::delete('biconnector', ['name' => self::REFRESH_DOMAIN_RETRY_OPTION]);
	}

	/**
	 * @return Result
	 */
	public static function deleteInstance(): Result
	{
		SupersetInitializerLogger::logInfo('Start deleting superset instance');

		$result = new Result();

		if (Option::get('biconnector', 'delete_error_emulation', 'N') === 'Y')
		{
			$result->addError(new Error('Superset instance deletion error emulation'));

			return $result;
		}

		if (
			!\Bitrix\Main\Loader::includeModule('bitrix24')
			&& !ConfigContainer::getConfigContainer()->isPortalIdVerified()
		)
		{
			self::fixDeleteTimestamp();
			self::clearSupersetData();
			self::setSupersetStatus(self::SUPERSET_STATUS_DOESNT_EXISTS);
			DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_DOESNT_EXISTS);

			return $result;
		}

		$response = IntegratorFactory::getInstance()->deleteSuperset();
		if (!$response->hasErrors())
		{
			Registrar::getRegistrar()->clear(__CLASS__ . '::' . __FUNCTION__);
			self::fixDeleteTimestamp();
		}
		else
		{
			$result->addErrors($response->getErrors());
		}

		return $result;
	}

	private static function pendingDeleteInstance(): Result
	{
		$result = new Result();

		if (self::isSupersetPendingDelete())
		{
			return $result;
		}

		$allowedStatuses = [
			self::SUPERSET_STATUS_READY,
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_ERROR,
			self::SUPERSET_STATUS_LIMIT_EXCEEDED,
		];
		if (!in_array(self::getSupersetStatus(), $allowedStatuses, true))
		{
			return $result;
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_PENDING_DELETE);
		self::suspendSuperset(['reason' => self::FREEZE_REASON_PENDING_DELETE]);

		$timeout = (int)Option::get(
			'biconnector',
			self::SUPERSET_PENDING_DELETE_TIMEOUT_OPTION,
			self::SUPERSET_PENDING_DELETE_DAYS * 24 * 60 * 60
		);

		$deleteAgentName = Agent::class . '::pendingDeleteCheck();';
		\CAgent::AddAgent(
			$deleteAgentName,
			'biconnector',
			'N',
			600,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $timeout, 'FULL')
		);

		$notifyBeforeTimeout = (int)Option::get(
			'biconnector',
			self::SUPERSET_PENDING_DELETE_NOTIFY_OPTION,
			self::SUPERSET_PENDING_DELETE_NOTIFY_DAYS * 24 * 60 * 60
		);
		$notifyDelay = $timeout - $notifyBeforeTimeout;
		if ($notifyDelay > 0)
		{
			$notifyAgentName = Agent::class . '::pendingDeleteNotify();';
			\CAgent::AddAgent(
				$notifyAgentName,
				'biconnector',
				'N',
				0,
				'',
				'Y',
				\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $notifyDelay, 'FULL')
			);
		}

		return $result;
	}

	/**
	 * Cancels a previously scheduled deferred deletion.
	 * Removes agents, restores status to LOAD, resumes instance if helm uninstall is done.
	 */
	public static function cancelPendingDelete(): void
	{
		if (!self::isSupersetPendingDelete())
		{
			return;
		}

		\CAgent::RemoveAgent(Agent::class . '::pendingDeleteCheck();', 'biconnector');
		\CAgent::RemoveAgent(Agent::class . '::pendingDeleteNotify();', 'biconnector');

		$statusBeforeLoad = self::getSupersetStatus();
		self::setSupersetStatus(self::SUPERSET_STATUS_LOAD);

		// PENDING_DELETE means suspend is still running. Defer to suspendAction callback, which will
		// see status=LOAD and trigger resume itself. See \Bitrix\BIConnector\Controller\Callback::suspendAction.
		if ($statusBeforeLoad === self::SUPERSET_STATUS_PENDING_DELETE)
		{
			return;
		}

		self::resumeSuperset(['reason' => self::FREEZE_REASON_PENDING_DELETE]);
	}

	public static function fixDeleteTimestamp(): void
	{
		Option::set('biconnector', self::SUPERSET_CLEAN_TIMESTAMP_OPTION, time());
	}

	public static function getDeleteTimestamp(): int
	{
		return (int)Option::get('biconnector', self::SUPERSET_CLEAN_TIMESTAMP_OPTION, 0);
	}

	private static function getCleanTimeout(): int
	{
		return (int)Option::get('biconnector', self::SUPERSET_CLEAN_TIMEOUT_OPTION, 24 * 60 * 60);
	}

	/**
	 * @deprecated Will be removed in future updates.
	 */
	public static function getAvailableToEnableSupersetTimestamp(): int
	{
		$cleanTimestamp = self::getDeleteTimestamp();

		return $cleanTimestamp + self::getCleanTimeout();
	}

	/**
	 * Clears all <b>LOCAL</b> data abount BI Constructor - tables and market apps.
	 *
	 * @return void
	 */
	public static function clearSupersetData(): void
	{
		$dashboards = SupersetDashboardTable::getList(['select' => ['*', 'APP']])->fetchCollection();
		foreach ($dashboards as $dashboard)
		{
			$app = $dashboard->getApp();
			if ($app && $dashboard->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
			{
				AppTable::uninstall($app->getCode());
				AppTable::update($app->getId(), ['ACTIVE' => 'N', 'INSTALLED' => 'N']);
			}

			$dashboardId = $dashboard->getId();
			$deleteResult = $dashboard->delete();
			if (!$deleteResult->isSuccess())
			{
				Logger::logErrors($deleteResult->getErrors(), ['clearSupersetData, deleting dashboard ' . $dashboardId]);
			}
		}

		$apps = AppTable::getList()->fetchCollection();
		foreach ($apps as $app)
		{
			if ($app->getCode() && MarketDashboardManager::isDatasetAppByAppCode($app->getCode()))
			{
				AppTable::uninstall($app->getCode());
				AppTable::update($app->getId(), ['ACTIVE' => 'N', 'INSTALLED' => 'N']);
			}
		}

		$connection = Application::getInstance()->getConnection();
		foreach (DatasetManager::getList() as $dataset)
		{
			// Don't use DatasetManager::delete because it uses events
			$datasetDeleteResult = ExternalDatasetTable::delete($dataset->getId());
			if ($datasetDeleteResult->isSuccess())
			{
				if ($dataset->getEnumType() === Type::Csv)
				{
					$tableName = Csv::TABLE_NAME_PREFIX . $dataset->getName();
					try
					{
						$connection->query(sprintf('DROP TABLE IF EXISTS `%s`;', $tableName));
					}
					catch (SqlQueryException $exception)
					{
						Logger::logErrors([new Error($exception->getMessage())], ['clearSupersetData, deleting dataset ' . $dataset->getId()]);
					}
				}
			}
			else
			{
				Logger::logErrors($datasetDeleteResult->getErrors(), ['clearSupersetData, deleting dataset ' . $dataset->getId()]);
			}
		}

		foreach (SupersetUserTable::getList()->fetchCollection() as $user)
		{
			$user->delete();
		}

		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_PERIOD_OPTION_NAME]);
		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_START_OPTION_NAME]);
		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_END_OPTION_NAME]);
		Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);
		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_INCLUDE_LAST_FILTER_DATE_OPTION_NAME]);
		Option::delete('biconnector', ['name' => SystemDashboardManager::SYSTEM_DASHBOARDS_DELETED_CODES_OPTION]);
		Option::delete('biconnector', ['name' => '~superset_init_required_dataset_table_hash']);
		Option::delete('biconnector', ['name' => '~superset_init_required_dataset_last_attempt']);
		Option::delete('biconnector', ['name' => DatasetSettings::TYPING_OPTION_NAME]);
		Option::delete('biconnector', ['name' => DatasetSettings::TYPING_LOCK_OPTION_NAME]);
		Option::delete('biconnector', ['name' => DataTimezone::OPTION_NAME]);

		\CUserOptions::DeleteOptionsByName('main.ui.filter', DashboardGrid::SUPERSET_DASHBOARD_GRID_ID);
		\CUserOptions::DeleteOptionsByName('main.ui.filter.presets', DashboardGrid::SUPERSET_DASHBOARD_GRID_ID);
		\CUserOptions::DeleteOptionsByName('biconnector', 'draft_guide');
		\CUserOptions::DeleteOptionsByName('biconnector', 'top_menu_dashboards');
		\CUserOptions::DeleteOptionsByName('biconnector', 'grid_pinned_dashboards');

		DomainLinkService::getInstance()->clearUnlinkedStatus();
		Registrar::getRegistrar()->clear(__CLASS__ . '::' . __FUNCTION__);

		SupersetDashboardTagTable::deleteByFilter(['>ID' => 0]);

		AccessInstaller::clearRelations();

		self::markSupersetInstanceExists(false);
		self::markSupersetInstanceSuspended(false);
		self::clearRebindRequired();
	}

	/**
	 * Saves user id and dashboard id before superset initialization to send appropriate notification when instance is up.
	 *
	 * @param int|null $dashboardId Opened dashboard id.
	 *
	 * @return void
	 */
	public static function saveInitData(?int $dashboardId = null): void
	{
		$userId = CurrentUser::get()->getId();
		Option::set('biconnector', self::SUPERSET_CREATED_BY_OPTION, $userId);
		if ($dashboardId)
		{
			Option::set('biconnector', self::SUPERSET_INITIAL_DASHBOARD_OPTION, $dashboardId);
		}
	}

	/**
	 * Handle enable BI Builder tool in general portal settings.
	 *
	 * @return void
	 */
	public static function onEnableBiBuilderTool(): void
	{
		if (self::isSupersetPendingDelete())
		{
			self::cancelPendingDelete();
		}

		$retryAgentName = Agent::class . '::deleteInstance();';
		\CAgent::RemoveAgent($retryAgentName, 'biconnector');
		Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);
	}

	/**
	 * Cancel pending deletion and immediately delete all data, starting fresh.
	 *
	 * @return void
	 */
	public static function resetSuperset(): void
	{
		$deleteAgentName = Agent::class . '::pendingDeleteCheck();';
		\CAgent::RemoveAgent($deleteAgentName, 'biconnector');

		$notifyAgentName = Agent::class . '::pendingDeleteNotify();';
		\CAgent::RemoveAgent($notifyAgentName, 'biconnector');

		$retryAgentName = Agent::class . '::deleteInstance();';
		\CAgent::RemoveAgent($retryAgentName, 'biconnector');
		Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);

		$deleteResult = self::deleteInstance();
		if (!$deleteResult->isSuccess())
		{
			\CAgent::AddAgent(
				$retryAgentName,
				'biconnector',
				'N',
				0,
				'',
				'Y',
				\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 86400, 'FULL'),
			);
			Option::set('biconnector', self::ERROR_DELETE_INSTANCE_OPTION, 'Y');
		}

		if (self::getSupersetStatus() !== self::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			self::clearSupersetData();
			self::setSupersetStatus(self::SUPERSET_STATUS_DELETED);
		}
	}

	/**
	 * Handle disable BI Builder tool in general portal settings.
	 *
	 * @return void
	 */
	public static function onDisableBiBuilderTool(): void
	{
			//add a few tab for graft in 26.300.100, remove soon
			if (SupersetHostMode::isSelfHosted())
			{
				return;
			}

			if (self::isSupersetPendingDelete())
			{
				return;
			}

			if (self::isRebindRequired())
			{
				// Local portalId is detached in rebind state.
				// Pull portalId back from proxy so the real DELETE can target it.
				$response = IntegratorFactory::getInstance()->registerPortal();
				$portalId = $response->getData()['portalId'] ?? null;
				if (!empty($portalId))
				{
					$config = ConfigContainer::getConfigContainer();
					$config->setPortalId($portalId);
					$config->setPortalIdVerified(true);
				}

				self::deleteInstance();
				self::clearSupersetData();
				// Proxy releases the SupersetServer record only after Callback::deleteAction is called.
				// Until then it keeps verified=Y, so a fast re-enable would loop on "Portal has already registered".
				// DELETED activates the create_superset stub,
				// which blocks the user from initiating any new proxy call. The callback flips DELETED->DOESNT_EXISTS
				// and sends a PULL event so the page reloads into a clean state. The safety-net agent unblocks
				// the user if the callback never arrives (see 0244532).
				self::setSupersetStatus(self::SUPERSET_STATUS_DELETED);
				\CAgent::AddAgent(
					Agent::class . '::recoverDeletedAfterRebindTimeout();',
					'biconnector',
					'N',
					0,
					'',
					'Y',
					\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 600, 'FULL'),
				);
				AccessInstaller::install();

				return;
			}

			if (!self::isSupersetInstanceExists() && !self::isSupersetLoading())
			{
				self::setSupersetStatus(self::SUPERSET_STATUS_DOESNT_EXISTS);
				self::deleteInstance();
				Registrar::getRegistrar()->clear(__CLASS__ . '::' . __FUNCTION__);

				return;
			}

			self::pendingDeleteInstance();
	}
}
