<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\BitrixGptBiAnnouncement;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware\Diagnostics;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Integration\Superset\Recovery\StatusRecoveryListener;
use Bitrix\BIConnector\Integration\Superset\Recovery\StatusRecoveryScheduler;
use Bitrix\BIConnector\Integration\Superset\Recovery\StatusRecoveryWindow;
use Bitrix\BIConnector\Superset\ActionFilter\ProxyAuth;
use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\BIConnector\Superset\Config\StoredOptionReader;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\Operation\DestructiveOperationOutcome;
use Bitrix\BIConnector\Superset\Operation\DestructiveOperationResolver;
use Bitrix\BIConnector\Superset\Operation\OperationIntentTable;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicense;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseState;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Source\Csv;
use Bitrix\Bitrix24\Feature;
use Bitrix\Intranet\Settings\Tools\BIConstructor;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SiteTable;
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
	public const GENERATION_BACKFILL_ATTEMPT_KEY = 'biconnector.superset.generation_backfill_attempt';
	public const GENERATION_REFRESH_KEY = 'biconnector.superset.generation_refresh_required';
	public const GENERATION_AWAIT_KEY = 'biconnector.superset.generation_awaited';
	public const REBIND_DELETE_CHECK_KEY = 'biconnector.superset.rebind_delete_check';

	private const DELETE_OUTCOME_KEY = 'deleteOutcome';
	private const DELETE_OPERATION_ID_KEY = 'deleteOperationId';

	private const SUPERSET_CLEAN_TIMESTAMP_OPTION = 'superset_clean_timestamp';
	private const SUPERSET_CLEAN_TIMEOUT_OPTION = 'superset_clean_timeout';
	private const SUPERSET_PENDING_DELETE_TIMEOUT_OPTION = 'superset_pending_delete_timeout';
	private const SUPERSET_PENDING_DELETE_DAYS = 30;
	private const SUPERSET_PENDING_DELETE_NOTIFY_OPTION = 'superset_pending_delete_notify_timeout';
	private const SUPERSET_PENDING_DELETE_NOTIFY_DAYS = 1;
	private const PENDING_DELETE_RETRY_DELAY = 86400;
	private const PENDING_DELETE_RETRY_AGENT = Agent::class . '::pendingDeleteRetry();';
	private const PENDING_DELETE_REPAIR_AGENT = Agent::class . '::pendingDeleteRepair();';
	private const PENDING_DELETE_REPAIR_DELAY = 60;
	// Never nested with another lock: GET_LOCK of MySQL 5.6 releases the lock held before it.
	private const PENDING_DELETE_LOCK_NAME = 'biconnector_superset_pending_delete';
	private const PENDING_DELETE_LOCK_WAIT = 30;

	private const SUPERSET_CREATED_BY_OPTION = 'superset_created_by_user';
	private const SUPERSET_INITIAL_DASHBOARD_OPTION = 'superset_initial_dashboard';

	private const REFRESH_DOMAIN_RETRY_OPTION = '~superset_refresh_domain_retry_scheduled';

	/**
	 * A series of generation requests: the first one, then one an hour for a day, whoever scheduled the agent.
	 * A shorter outage of the service does not end the series. A deletion awaiting the generation is held
	 * for the series plus the next attempt of its repeating agent: about a day for the deferred deletion,
	 * up to about two days for the first disabling, which is retried once a day.
	 * The keys of the series live longer than the series itself.
	 */
	public const GENERATION_BACKFILL_INTERVAL = 3600;
	private const GENERATION_BACKFILL_SERIES_LENGTH = 86400;
	private const GENERATION_BACKFILL_ATTEMPT_LIMIT =
		1 + self::GENERATION_BACKFILL_SERIES_LENGTH / self::GENERATION_BACKFILL_INTERVAL
	;
	private const GENERATION_BACKFILL_ATTEMPT_TTL = 2592000;
	private const GENERATION_REFRESH_DELAY = 300;

	private const REBIND_DELETE_CHECK_AGENT = Agent::class . '::checkRebindDelete();';
	private const REBIND_DELETE_CHECK_TTL = 2 * self::GENERATION_BACKFILL_SERIES_LENGTH;
	private const REBIND_REGISTRATION_LOCK_NAME = 'biconnector_superset_rebind_registration';
	private const REBIND_REGISTRATION_LOCK_WAIT = 5;

	private const SUPERSET_INSTANCE_SUSPENDED_OPTION = '~superset_instance_suspended';

	private const SUPERSET_INSTANCE_EXISTS_OPTION = '~superset_instance_exists';

	public const SUPERSET_UPDATING_OPTION = '~superset_updating';
	public const SUPERSET_UPDATING_TTL_SECONDS = 1800;

	/**
	 * Guards the critical section that opens a recovery series: exactly one series state and exactly
	 * one recovery agent row must exist per portal.
	 */
	private const RECOVERY_START_LOCK_NAME = 'biconnector_superset_recovery_start';

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

		if (SupersetHostMode::isSelfHosted())
		{
			$licenseState = SelfHostedLicense::getInstance()->getState();
			if ($licenseState !== SelfHostedLicenseState::Active)
			{
				SupersetInitializerLogger::logInfo(
					'Skip superset startup: self-hosted license extension is not active',
					['license_state' => $licenseState->value],
				);

				return $status;
			}
		}

		$newStatus = self::startSupersetInitialize();
		if (self::getSupersetStatus() === $newStatus)
		{
			return $status;
		}

		if (!self::isStartupOutcomeApplicable())
		{
			self::logOutdatedStartupOutcome($newStatus);

			return $status;
		}

		self::setSupersetStatus($newStatus);

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
			self::dropStaleRecoveryState();

			return $status;
		}

		$statusBeforeStartup = self::startupSuperset();
		self::ensureRecoveryStarted();

		return $statusBeforeStartup;
	}

	/**
	 * Drops the recovery series state left over from a finished incident.
	 *
	 * The state normally dies on the status event, but a portal that has not run the client updater
	 * yet has no listener registered, and then a leftover state would block any further series.
	 */
	private static function dropStaleRecoveryState(): void
	{
		if (SupersetHostMode::isSelfHosted())
		{
			return;
		}

		if ((new StatusRecoveryWindow())->isTracked())
		{
			StatusRecoveryListener::resetRecoveryState();
		}
	}

	/**
	 * Starts the recovery series if the portal is still in ERROR after a user-initiated startup.
	 *
	 * This is the only place that opens a series. Attempts must be spread over real user visits:
	 * a gateway incident puts hundreds of portals into ERROR at the same moment, so opening
	 * a series on the ERROR transition itself would send synchronous bursts of attempts
	 * to the proxy. Portals where BI is never opened do not retry at all.
	 *
	 * A series that is already running or already exhausted is not restarted by a visit, otherwise
	 * active users would extend automatic retries indefinitely. The one-shot startup attempt above
	 * stays available on every visit regardless of the series state. A running series that has lost
	 * its agent row is an exception: the visit restores the agent, see ensureRecoveryAgentScheduled().
	 *
	 * Restoring the agent is allowed in both ERROR and LOAD, matching the statuses the agent itself keeps
	 * working in. A running series survives LOAD: the startup above answers with LOAD whenever the proxy
	 * reports the instance as unavailable (555, 502, 503, 504), so the very visit that could restore the
	 * agent is also the one that moves the portal out of ERROR. Restricting the restore to ERROR would
	 * leave such a series without attempts forever. Opening a new series stays ERROR-only: a portal in
	 * an ordinary loading state has not failed and needs no series.
	 *
	 * Opening the series is serialized by a named lock: CAgent::AddAgent() is a separate SELECT and
	 * INSERT with no unique key on the agent name, so parallel hits could otherwise leave two recovery
	 * agent rows in b_agent and double every attempt.
	 */
	private static function ensureRecoveryStarted(): void
	{
		if (SupersetHostMode::isSelfHosted())
		{
			return;
		}

		$status = self::getSupersetStatus();
		if ($status === self::SUPERSET_STATUS_READY)
		{
			self::dropStaleRecoveryState();

			return;
		}

		$seriesStatuses = [
			self::SUPERSET_STATUS_ERROR,
			self::SUPERSET_STATUS_LOAD,
		];

		if (!in_array($status, $seriesStatuses, true))
		{
			return;
		}

		if (self::isRebindRequired() || !\Bitrix\BIConnector\Configuration\Feature::isBuilderEnabled())
		{
			return;
		}

		$window = new StatusRecoveryWindow();
		if ($window->isTracked())
		{
			self::ensureRecoveryAgentScheduled($window);

			return;
		}

		if ($status !== self::SUPERSET_STATUS_ERROR)
		{
			return;
		}

		self::openRecoverySeries($window);
	}

	/**
	 * Opens a series: the state of the window and the agent row appear together or not at all.
	 * A state left without an agent would make every further visit see a running series and never
	 * schedule an attempt again.
	 */
	private static function openRecoverySeries(StatusRecoveryWindow $window): void
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::RECOVERY_START_LOCK_NAME))
		{
			// A parallel hit is opening the series right now, nothing left to do here.
			return;
		}

		try
		{
			if ($window->isTracked())
			{
				return;
			}

			$now = time();
			$window->open($now);

			$attempt = $window->resolveNextAttempt($now);
			if ($attempt === null || !self::scheduleRecoveryAttempt($attempt['delay']))
			{
				$window->clear();

				return;
			}

			SupersetInitializerLogger::logInfo('Superset recovery series started', [
				'next_attempt_in' => $attempt['delay'],
			]);
		}
		finally
		{
			$connection->unlock(self::RECOVERY_START_LOCK_NAME);
		}
	}

	/**
	 * Restores the agent of a running series that has lost its row: the process may die between
	 * open() and the scheduling call, and the rollback above cannot cover that. Without the agent
	 * the portal would keep a series that never performs an attempt.
	 *
	 * An exhausted series is left alone: its state is deliberately kept as a mark of a finished
	 * incident, so there is nothing to reschedule. The b_agent read happens only for a live series
	 * of a portal in ERROR or LOAD, which is a rare branch by itself.
	 *
	 * A failed restore keeps the state as is: the next visit repeats the check.
	 */
	private static function ensureRecoveryAgentScheduled(StatusRecoveryWindow $window): void
	{
		if ($window->resolveNextAttempt(time()) === null || StatusRecoveryScheduler::isScheduled())
		{
			return;
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::RECOVERY_START_LOCK_NAME))
		{
			return;
		}

		try
		{
			$attempt = $window->resolveNextAttempt(time());
			if ($attempt === null || StatusRecoveryScheduler::isScheduled())
			{
				return;
			}

			if (!self::scheduleRecoveryAttempt($attempt['delay']))
			{
				return;
			}

			SupersetInitializerLogger::logInfo('Superset recovery agent restored', [
				'next_attempt_in' => $attempt['delay'],
			]);
		}
		finally
		{
			$connection->unlock(self::RECOVERY_START_LOCK_NAME);
		}
	}

	/**
	 * Puts the agent row of the series in place, reporting a failure instead of throwing: recovery
	 * bookkeeping is a side effect of a page visit and must not break the page.
	 */
	private static function scheduleRecoveryAttempt(int $delaySeconds): bool
	{
		try
		{
			if (StatusRecoveryScheduler::schedule($delaySeconds))
			{
				return true;
			}

			SupersetInitializerLogger::logErrors(
				[new Error('Cannot schedule superset recovery agent')],
			);
		}
		catch (\Throwable $exception)
		{
			SupersetInitializerLogger::logErrors(
				[new Error($exception->getMessage())],
				['message' => 'error while scheduling superset recovery agent'],
			);
		}

		return false;
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

		$isInstanceCreatedNow = !self::isSupersetInstanceExists();

		self::setSupersetStatus(self::SUPERSET_STATUS_READY);
		self::markSupersetInstanceExists(true);
		self::markSupersetInstanceSuspended(false);
		if ($isInstanceCreatedNow)
		{
			self::markLocalDataGeneration();
		}

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

	private static function markLocalDataGeneration(): void
	{
		$config = ConfigContainer::getConfigContainer();
		$config->markLocalDataGeneration($config->getServerGeneration());
	}

	public static function requestServerGenerationRefresh(): void
	{
		if (SupersetHostMode::isSelfHosted() || !self::isSupersetInstanceExists())
		{
			return;
		}

		$storage = ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$storage->set(self::GENERATION_REFRESH_KEY, 1, self::GENERATION_BACKFILL_ATTEMPT_TTL);
		$storage->delete(self::GENERATION_BACKFILL_ATTEMPT_KEY);

		self::scheduleHourlySeriesAgent(self::class . '::backfillServerGeneration();');
	}

	private static function scheduleHourlySeriesAgent(string $agentName): void
	{
		$nextExec = \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::GENERATION_REFRESH_DELAY, 'FULL');

		// Adding an agent that is already scheduled changes nothing, so the live one is moved instead;
		// the interval is set as well, so a row of any origin runs the same series.
		$agent = \CAgent::GetList(
			['ID' => 'ASC'],
			[
				'=NAME' => $agentName,
				'MODULE_ID' => 'biconnector',
			],
		)->Fetch();
		if (is_array($agent))
		{
			\CAgent::Update($agent['ID'], [
				'NEXT_EXEC' => $nextExec,
				'AGENT_INTERVAL' => self::GENERATION_BACKFILL_INTERVAL,
			]);

			return;
		}

		\CAgent::AddAgent(
			$agentName,
			'biconnector',
			'N',
			self::GENERATION_BACKFILL_INTERVAL,
			'',
			'Y',
			$nextExec,
		);
	}

	public static function backfillServerGeneration(): string
	{
		if (SupersetHostMode::isSelfHosted())
		{
			return self::stopServerGenerationBackfill();
		}

		$config = ConfigContainer::getConfigContainer();
		if (
			($config->getServerGeneration() !== '' && !self::isServerGenerationRefreshRequested())
			|| !$config->isPortalIdVerified()
			|| !self::isSupersetInstanceExists()
		)
		{
			return self::stopServerGenerationBackfill();
		}

		$response = IntegratorFactory::getInstance()->registerPortal(raiseRebindMode: false);
		if (self::isAnswerOfAnotherPortal($response))
		{
			SupersetInitializerLogger::logErrors(
				$response->getErrors() ?: [new Error('The superset server of the portal belongs to another portal')],
				['message' => 'the generation is left to the rebind confirmed by the administrator'],
			);

			return self::stopServerGenerationBackfill();
		}

		$generation = (string)($response->getData()['generation'] ?? '');
		if ($generation !== '')
		{
			$config->setServerGeneration($generation);

			return self::stopServerGenerationBackfill();
		}

		if (Diagnostics::isDiagnosedRefusal($response->getErrors()))
		{
			SupersetInitializerLogger::logErrors(
				$response->getErrors(),
				['message' => 'the superset server of the portal is not resolved unambiguously'],
			);

			return self::stopServerGenerationBackfill();
		}

		return self::retryServerGenerationBackfill($response);
	}

	private static function isAnswerOfAnotherPortal(IntegratorResponse $response): bool
	{
		return ($response->getData()['rebind'] ?? false) === true;
	}

	private static function retryServerGenerationBackfill(IntegratorResponse $response): string
	{
		$storage = ServiceLocator::getInstance()->get(PersistentStorageInterface::class);

		$attempt = (int)$storage->get(self::GENERATION_BACKFILL_ATTEMPT_KEY, 0) + 1;
		if ($attempt >= self::GENERATION_BACKFILL_ATTEMPT_LIMIT)
		{
			SupersetInitializerLogger::logErrors(
				$response->getErrors() ?: [new Error('The superset server did not report its generation')],
				[
					'message' => 'the generation of the current superset server is left unknown',
					'attempts' => $attempt,
					'status' => $response->getStatus(),
				],
			);

			return self::stopServerGenerationBackfill();
		}

		$storage->set(self::GENERATION_BACKFILL_ATTEMPT_KEY, $attempt, self::GENERATION_BACKFILL_ATTEMPT_TTL);

		return self::class . '::backfillServerGeneration();';
	}

	private static function stopServerGenerationBackfill(): string
	{
		$storage = ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$storage->delete(self::GENERATION_BACKFILL_ATTEMPT_KEY);
		$storage->delete(self::GENERATION_REFRESH_KEY);

		return '';
	}

	private static function isServerGenerationRefreshRequested(): bool
	{
		return (bool)ServiceLocator::getInstance()
			->get(PersistentStorageInterface::class)
			->get(self::GENERATION_REFRESH_KEY, 0)
		;
	}

	/**
	 * Pushes the current biconnector access key to the proxy.
	 *
	 * Runs on a READY transition only: the underlying proxy action is guarded by a middleware that
	 * requires the READY status, so a call made during LOAD or ERROR would be rejected. Repeating
	 * the call with the same key is safe, therefore no "already synchronized" flag is kept.
	 *
	 * The push explicitly opts out of status arbitration: it is a background follow-up, so a gateway
	 * failure must stay in the log instead of dropping a live instance back to ERROR or LOAD.
	 */
	public static function syncBiconnectorTokenToProxy(): void
	{
		if (SupersetHostMode::isSelfHosted() || !self::isSupersetReady())
		{
			return;
		}

		$accessKey = KeyManager::getAccessKey();
		if ($accessKey === null)
		{
			SupersetInitializerLogger::logErrors(
				[new Error('Cannot sync biconnector token: access key is missing')],
			);

			return;
		}

		$response = IntegratorFactory::getInstance()->changeBiconnectorToken(
			$accessKey,
			arbitrateInstanceStatus: false,
		);
		if ($response->hasErrors())
		{
			SupersetInitializerLogger::logErrors(
				$response->getErrors(),
				['message' => 'error while syncing biconnector token after recovery'],
			);

			return;
		}

		SupersetInitializerLogger::logInfo('Biconnector token synchronized with proxy');
	}

	public static function freezeSuperset(array $params = []): DestructiveOperationOutcome
	{
		$response = IntegratorFactory::getInstance()->freezeSuperset($params);

		return DestructiveOperationResolver::resolve(OperationIntentTable::OPERATION_FREEZE, $response);
	}

	public static function unfreezeSuperset(array $params = []): IntegratorResponse
	{
		$proxyIntegrator = IntegratorFactory::getInstance();

		return $proxyIntegrator->unfreezeSuperset($params);
	}

	public static function suspendSuperset(array $params = []): DestructiveOperationOutcome
	{
		$response = IntegratorFactory::getInstance()->suspendSuperset($params);

		return DestructiveOperationResolver::resolve(OperationIntentTable::OPERATION_SUSPEND, $response);
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
			// unfreezing comes through the same point, so the date is stamped on the first startup only
			if (!self::isSupersetInstanceExists())
			{
				Option::set(
					'biconnector',
					BitrixGptBiAnnouncement::INSTANCE_STARTUP_DATE_OPTION,
					(string)time(),
				);
			}

			Option::set('biconnector', self::SUPERSET_INSTANCE_EXISTS_OPTION, 'Y');
		}
		else
		{
			Option::delete('biconnector', ['name' => self::SUPERSET_INSTANCE_EXISTS_OPTION]);
			Option::delete('biconnector', ['name' => BitrixGptBiAnnouncement::INSTANCE_STARTUP_DATE_OPTION]);
		}
	}

	public static function isSupersetInstanceExists(): bool
	{
		return Option::get('biconnector', self::SUPERSET_INSTANCE_EXISTS_OPTION, 'N') === 'Y';
	}

	/**
	 * Remembers that the proxy froze the instance for a Superset version update.
	 * The mark lives only while the instance is loading and expires after a TTL,
	 * so a lost unfreeze notification cannot keep the update stub forever.
	 */
	public static function markSupersetUpdating(bool $value): void
	{
		if ($value)
		{
			Option::set('biconnector', self::SUPERSET_UPDATING_OPTION, (string)time());
		}
		elseif (Option::get('biconnector', self::SUPERSET_UPDATING_OPTION, '') !== '')
		{
			Option::delete('biconnector', ['name' => self::SUPERSET_UPDATING_OPTION]);
		}
	}

	public static function isSupersetUpdating(): bool
	{
		// A self-hosted instance is updated by the portal administrator, the proxy signal never applies to it.
		if (SupersetHostMode::isSelfHosted())
		{
			return false;
		}

		$markedAt = (int)Option::get('biconnector', self::SUPERSET_UPDATING_OPTION, 0);
		if ($markedAt <= 0 || time() - $markedAt >= self::SUPERSET_UPDATING_TTL_SECONDS)
		{
			return false;
		}

		return self::isSupersetLoading();
	}

	public static function setSupersetStatus(string $status): void
	{
		self::replaceSupersetStatus(self::getSupersetStatus(), $status);
	}

	private static function replaceSupersetStatus(string $oldStatus, string $status): void
	{
		self::writeSupersetStatusSilently($status);
		self::announceSupersetStatus($oldStatus, $status);
	}

	private static function writeSupersetStatusSilently(string $status): void
	{
		SupersetInitializerLogger::logInfo('Superset status changed to ' . $status);
		self::getStatusContainer()->set($status);
	}

	private static function announceSupersetStatus(string $oldStatus, string $status): void
	{
		if ($status !== self::SUPERSET_STATUS_LOAD)
		{
			self::markSupersetUpdating(false);
		}

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
		return self::getStatusContainer()->get();
	}

	private static function getStatusContainer(): SupersetStatusOptionContainer
	{
		if (!isset(self::$statusContainer))
		{
			self::$statusContainer = new SupersetStatusOptionContainer();
		}

		return self::$statusContainer;
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
		ConfigContainer::getConfigContainer()->setServerGeneration((string)($response->getData()['generation'] ?? ''));

		$responseStatus = $response->getStatus();

		$status = self::SUPERSET_STATUS_LOAD;
		if ($responseStatus === IntegratorResponse::STATUS_CREATED)
		{
			// BI may have been disabled while the request was in flight: a portal that is being deleted
			// must not be pulled back to READY by a finished attempt.
			if (self::isSuccessfulStartupOutcomeApplicable())
			{
				$responseData = $response->getData();
				self::enableSuperset($responseData['superset_address'] ?? '');
				$status = self::SUPERSET_STATUS_READY;
			}
			else
			{
				$status = self::logOutdatedStartupOutcome(self::SUPERSET_STATUS_READY);
			}
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

	/**
	 * Whether an unsuccessful outcome of a startup attempt — ERROR, LOAD or LIMIT_EXCEEDED — may still be
	 * applied to the instance status.
	 *
	 * The startup request is not instant: while it is in flight, an activation callback may promote the
	 * portal to READY, the instance may be dropped by the user or blocked by a limit. Such a state belongs
	 * to a newer decision, so only a portal that is still inside the startup set may be moved.
	 *
	 * @see isSuccessfulStartupOutcomeApplicable() for the wider set a 201 response is applied from.
	 */
	private static function isStartupOutcomeApplicable(): bool
	{
		$startupStatuses = [
			self::SUPERSET_STATUS_DOESNT_EXISTS,
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_ERROR,
		];

		return in_array(self::getSupersetStatus(), $startupStatuses, true);
	}

	/**
	 * Whether a successful startup response (201) may still be applied to the instance status.
	 *
	 * The set is wider than the startup one: a 201 answered to a portal blocked by a tariff limit is the
	 * normal way back to READY once the limit is lifted, and the same call is the only route out of
	 * LIMIT_EXCEEDED. Excluded are the deletion statuses only — a portal whose instance is being dropped
	 * must not be restored by a response of an attempt that started before the deletion.
	 */
	private static function isSuccessfulStartupOutcomeApplicable(): bool
	{
		$applicableStatuses = [
			self::SUPERSET_STATUS_DOESNT_EXISTS,
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_ERROR,
			self::SUPERSET_STATUS_LIMIT_EXCEEDED,
			self::SUPERSET_STATUS_READY,
		];

		return in_array(self::getSupersetStatus(), $applicableStatuses, true);
	}

	/**
	 * Reports a startup outcome dropped as outdated.
	 *
	 * @param string $outcomeStatus Status the finished attempt would have applied.
	 *
	 * @return string Status the portal has actually reached meanwhile.
	 */
	private static function logOutdatedStartupOutcome(string $outcomeStatus): string
	{
		$currentStatus = self::getSupersetStatus();
		SupersetInitializerLogger::logInfo('Skip outdated superset startup outcome', [
			'current_status' => $currentStatus,
			'startup_status' => $outcomeStatus,
		]);

		return $currentStatus;
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

	/**
	 * Whether a successful activation callback from the proxy may be applied right now.
	 *
	 * ERROR is accepted alongside LOAD: an instance may finish starting up asynchronously after
	 * the portal has already flipped to ERROR, and dropping such a callback used to leave the
	 * portal stuck until support intervened.
	 */
	public static function isActivationCallbackApplicable(): bool
	{
		$applicableStatuses = [
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_ERROR,
		];

		if (!in_array(self::getSupersetStatus(), $applicableStatuses, true))
		{
			return false;
		}

		return !self::isRebindRequired() && !self::isSupersetPendingDelete();
	}

	/**
	 * Applies a failed startup outcome, whether it came from the startup response or from a callback.
	 *
	 * The failure is always logged, but the status is moved only while the portal is still inside the
	 * startup set: a failure reported after the instance became READY, was dropped or hit a tariff
	 * limit belongs to a finished attempt and must not overwrite the newer state.
	 */
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

		if (!self::isStartupOutcomeApplicable())
		{
			SupersetInitializerLogger::logInfo('Skip outdated superset startup failure', [
				'current_status' => self::getSupersetStatus(),
			]);

			return;
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_ERROR);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_ERROR);
	}

	/**
	 * Applies a tariff limit reported by a startup response.
	 *
	 * Like a failed startup, the limit is always logged, but the status is moved only while the portal is
	 * still inside the startup set: a limit that arrived after the instance became READY or was dropped
	 * belongs to a finished attempt and must not overwrite the newer state.
	 */
	public static function onLimitExceeded(Error ...$errors): void
	{
		SupersetInitializerLogger::logErrors($errors, ['message' => 'error while startup superset']);

		if (!self::isStartupOutcomeApplicable())
		{
			self::logOutdatedStartupOutcome(self::SUPERSET_STATUS_LIMIT_EXCEEDED);

			return;
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_LIMIT_EXCEEDED);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_LIMIT_EXCEEDED);
	}

	public static function onBitrix24LicenseChange(): void
	{
		// A tariff change may not move the instance status, so the status event is not enough
		// to drop the recovery series here.
		StatusRecoveryListener::resetRecoveryState();

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

			return self::withDeleteOutcome($result, DestructiveOperationOutcome::Undetermined);
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

			return self::withDeleteOutcome($result, DestructiveOperationOutcome::Success);
		}

		$response = IntegratorFactory::getInstance()->deleteSuperset();
		$outcome = DestructiveOperationResolver::resolve(OperationIntentTable::OPERATION_DELETE, $response);
		if ($outcome === DestructiveOperationOutcome::Success)
		{
			self::forgetDeletedInstanceRegistration(__CLASS__ . '::' . __FUNCTION__);
		}
		else
		{
			$result->addErrors($response->getErrors());
		}

		return self::withDeleteOutcome($result, $outcome, DestructiveOperationResolver::readOperationId($response));
	}

	public static function getDeleteOutcome(Result $deleteResult): DestructiveOperationOutcome
	{
		$outcome = $deleteResult->getData()[self::DELETE_OUTCOME_KEY] ?? null;

		return $outcome instanceof DestructiveOperationOutcome ? $outcome : DestructiveOperationOutcome::Undetermined;
	}

	public static function getDeleteOperationId(Result $deleteResult): string
	{
		$operationId = $deleteResult->getData()[self::DELETE_OPERATION_ID_KEY] ?? '';

		return is_string($operationId) ? $operationId : '';
	}

	private static function withDeleteOutcome(
		Result $result,
		DestructiveOperationOutcome $outcome,
		string $operationId = '',
	): Result
	{
		return $result->setData([
			self::DELETE_OUTCOME_KEY => $outcome,
			self::DELETE_OPERATION_ID_KEY => $operationId,
		]);
	}

	private static function forgetDeletedInstanceRegistration(string $reason): void
	{
		Registrar::getRegistrar()->clear($reason);
		self::fixDeleteTimestamp();
	}

	private static function pendingDeleteInstance(): Result
	{
		self::startPendingDelete(isRetryAgent: false);

		return new Result();
	}

	/**
	 * @return string Own agent name while the service has not answered, empty string to stop retrying.
	 */
	public static function retryPendingDelete(): string
	{
		if (self::isPendingDeleteToBeRetried(self::startPendingDelete(isRetryAgent: true)))
		{
			return self::PENDING_DELETE_RETRY_AGENT;
		}

		return '';
	}

	private static function isPendingDeleteToBeRetried(?DestructiveOperationOutcome $outcome): bool
	{
		return
			$outcome === DestructiveOperationOutcome::Undetermined
			|| $outcome === DestructiveOperationOutcome::AwaitingGeneration
		;
	}

	private static function startPendingDelete(bool $isRetryAgent): ?DestructiveOperationOutcome
	{
		if (!self::acquirePendingDeleteLock(__FUNCTION__))
		{
			self::scheduleRetryOfUnsetPendingDelete(DestructiveOperationOutcome::Undetermined, $isRetryAgent);

			return DestructiveOperationOutcome::Undetermined;
		}

		try
		{
			$initialStatus = self::readStatusToStartPendingDeleteFrom($isRetryAgent);
			if ($initialStatus === null)
			{
				return null;
			}

			$awaitOutcome = DestructiveOperationResolver::checkGenerationAwait();
			if ($awaitOutcome !== null)
			{
				self::scheduleRetryOfUnsetPendingDelete($awaitOutcome, $isRetryAgent);

				return $awaitOutcome;
			}

			self::writeSupersetStatusSilently(self::SUPERSET_STATUS_PENDING_DELETE);
		}
		finally
		{
			self::releasePendingDeleteLock();
		}

		$outcome = self::suspendSuperset(['reason' => self::FREEZE_REASON_PENDING_DELETE]);

		return self::runUnderPendingDeleteLock(
			static fn(): ?DestructiveOperationOutcome => self::settlePendingDeleteStart(
				$initialStatus,
				$outcome,
				$isRetryAgent,
			),
			'settlePendingDeleteStart',
		);
	}

	private static function readStatusToStartPendingDeleteFrom(bool $isRetryAgent): ?string
	{
		$allowedStatuses = [
			self::SUPERSET_STATUS_READY,
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_ERROR,
			self::SUPERSET_STATUS_LIMIT_EXCEEDED,
		];
		$status = self::getStatusContainer()->getStored();
		if (!in_array($status, $allowedStatuses, true))
		{
			return null;
		}

		if ($isRetryAgent && (!self::isPendingDeleteRetryScheduled() || self::isBiBuilderToolEnabled()))
		{
			return null;
		}

		return $status;
	}

	public static function isBiBuilderToolEnabled(): bool
	{
		return
			Loader::includeModule('intranet')
			&& StoredOptionReader::read('intranet', (new BIConstructor())->getOptionCode(), 'Y') === 'Y'
		;
	}

	private static function isPendingDeleteRetryScheduled(): bool
	{
		$connectionPool = Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);
		try
		{
			$agent = \CAgent::GetList(
				[],
				[
					'=NAME' => self::PENDING_DELETE_RETRY_AGENT,
					'MODULE_ID' => 'biconnector',
				],
			)->Fetch();

			return is_array($agent);
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}
	}

	private static function settlePendingDeleteStart(
		string $initialStatus,
		DestructiveOperationOutcome $outcome,
		bool $isRetryAgent,
	): ?DestructiveOperationOutcome
	{
		$status = self::getStatusContainer()->getStored();

		if ($status === self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED)
		{
			self::clearPendingDeleteRetry();
			self::schedulePendingDeleteAgents();

			return DestructiveOperationOutcome::Success;
		}

		if ($status !== self::SUPERSET_STATUS_PENDING_DELETE)
		{
			DestructiveOperationResolver::dropGenerationAwait();
			if ($status === self::SUPERSET_STATUS_LOAD && self::isSuspendRefused($outcome))
			{
				self::writeSupersetStatusSilently($initialStatus);
			}

			return null;
		}

		if ($outcome === DestructiveOperationOutcome::Success)
		{
			self::clearPendingDeleteRetry();
			self::announceSupersetStatus($initialStatus, self::SUPERSET_STATUS_PENDING_DELETE);
			self::schedulePendingDeleteAgents();

			return $outcome;
		}

		self::writeSupersetStatusSilently($initialStatus);
		DestructiveOperationResolver::awaitGenerationBeforeRetry($outcome);
		self::scheduleRetryOfUnsetPendingDelete($outcome, $isRetryAgent);

		return $outcome;
	}

	private static function isSuspendRefused(DestructiveOperationOutcome $outcome): bool
	{
		return
			$outcome === DestructiveOperationOutcome::FinalRefusal
			|| $outcome === DestructiveOperationOutcome::AwaitingGeneration
		;
	}

	private static function schedulePendingDeleteAgents(): void
	{
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
	}

	private static function scheduleRetryOfUnsetPendingDelete(
		DestructiveOperationOutcome $outcome,
		bool $isRetryAgent,
	): void
	{
		if (!$isRetryAgent && self::isPendingDeleteToBeRetried($outcome))
		{
			self::schedulePendingDeleteRetry();
		}
	}

	private static function schedulePendingDeleteRetry(): void
	{
		\CAgent::AddAgent(
			self::PENDING_DELETE_RETRY_AGENT,
			'biconnector',
			'N',
			self::PENDING_DELETE_RETRY_DELAY,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::PENDING_DELETE_RETRY_DELAY, 'FULL'),
		);
	}

	/**
	 * Enabling the tool and resetting the instance cancel the deferred deletion here, so the awaiting
	 * of the generation is dropped with it, whichever repeating caller marked it.
	 */
	private static function clearPendingDeleteRetry(): void
	{
		\CAgent::RemoveAgent(self::PENDING_DELETE_RETRY_AGENT, 'biconnector');
		DestructiveOperationResolver::dropGenerationAwait();
	}

	/**
	 * Cancels a previously scheduled deferred deletion.
	 * Removes agents, restores status to LOAD. Runs under the lock of the deferred deletion.
	 *
	 * @return bool Whether the instance is to be resumed once the lock is released.
	 */
	private static function cancelPendingDelete(): bool
	{
		$status = self::getStatusContainer()->getStored();
		if (!self::isPendingDeleteStatus($status))
		{
			return false;
		}

		\CAgent::RemoveAgent(Agent::class . '::pendingDeleteCheck();', 'biconnector');
		\CAgent::RemoveAgent(Agent::class . '::pendingDeleteNotify();', 'biconnector');

		self::replaceSupersetStatus($status, self::SUPERSET_STATUS_LOAD);

		// PENDING_DELETE means suspend is still running. Defer to suspendAction callback, which will
		// see status=LOAD and trigger resume itself. See \Bitrix\BIConnector\Controller\Callback::suspendAction.
		return $status === self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED;
	}

	private static function isPendingDeleteStatus(string $status): bool
	{
		return
			$status === self::SUPERSET_STATUS_PENDING_DELETE
			|| $status === self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED
		;
	}

	public static function applySuspendCallback(): ?bool
	{
		self::markSupersetInstanceSuspended(true);

		$isResumeRequired = self::runUnderPendingDeleteLock(
			static function (): bool {
				$status = self::getStatusContainer()->getStored();
				SupersetInitializerLogger::logInfo('Portal got suspend action', ['current_status' => $status]);

				// suspend finished. Promote status so cancelPendingDelete() knows
				// it is safe to call resume immediately if the user re-enables BI later.
				if ($status === self::SUPERSET_STATUS_PENDING_DELETE)
				{
					self::replaceSupersetStatus($status, self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED);

					return false;
				}

				// User already re-enabled BI — cancelPendingDelete() set status to LOAD and
				// deferred resume. suspend is done now, so trigger resume.
				return $status === self::SUPERSET_STATUS_LOAD;
			},
			__FUNCTION__,
		);

		if ($isResumeRequired === null)
		{
			self::schedulePendingDeleteRepair();
		}

		return $isResumeRequired;
	}

	public static function repairPendingDelete(): string
	{
		$isResumeRequired = self::runUnderPendingDeleteLock(
			static fn(): bool => self::applySkippedPendingDeleteSteps(),
			__FUNCTION__,
		);

		if ($isResumeRequired === null)
		{
			return self::PENDING_DELETE_REPAIR_AGENT;
		}

		if ($isResumeRequired)
		{
			self::resumeSuperset(['reason' => self::FREEZE_REASON_PENDING_DELETE]);
		}

		return '';
	}

	private static function applySkippedPendingDeleteSteps(): bool
	{
		$status = self::getStatusContainer()->getStored();
		$isSuspended = StoredOptionReader::read('biconnector', self::SUPERSET_INSTANCE_SUSPENDED_OPTION, 'N') === 'Y';

		if ($status === self::SUPERSET_STATUS_PENDING_DELETE && $isSuspended)
		{
			$status = self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED;
			self::replaceSupersetStatus(self::SUPERSET_STATUS_PENDING_DELETE, $status);
		}

		if (self::isPendingDeleteStatus($status) && self::isBiBuilderToolEnabled())
		{
			return self::cancelPendingDelete();
		}

		return $status === self::SUPERSET_STATUS_LOAD && $isSuspended;
	}

	private static function schedulePendingDeleteRepair(): void
	{
		\CAgent::AddAgent(
			name: self::PENDING_DELETE_REPAIR_AGENT,
			module: 'biconnector',
			period: 'N',
			interval: self::PENDING_DELETE_REPAIR_DELAY,
			active: 'Y',
			next_exec: \ConvertTimeStamp(
				time() + \CTimeZone::GetOffset() + self::PENDING_DELETE_REPAIR_DELAY,
				'FULL',
			),
			existError: false,
		);
	}

	private static function runUnderPendingDeleteLock(callable $action, string $actionName): mixed
	{
		if (!self::acquirePendingDeleteLock($actionName))
		{
			return null;
		}

		try
		{
			return $action();
		}
		finally
		{
			self::releasePendingDeleteLock();
		}
	}

	private static function acquirePendingDeleteLock(string $actionName): bool
	{
		$isLocked = Application::getConnection()->lock(
			self::PENDING_DELETE_LOCK_NAME,
			self::PENDING_DELETE_LOCK_WAIT,
		);
		if (!$isLocked)
		{
			SupersetInitializerLogger::logErrors(
				[new Error('The lock of the deferred deletion is held by another process')],
				[
					'action' => $actionName,
					'waitSeconds' => self::PENDING_DELETE_LOCK_WAIT,
				],
			);
		}

		return $isLocked;
	}

	private static function releasePendingDeleteLock(): void
	{
		Application::getConnection()->unlock(self::PENDING_DELETE_LOCK_NAME);
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
		try
		{
			$dashboards = SupersetDashboardTable::getList(['select' => ['*', 'APP']])->fetchCollection();
			foreach ($dashboards as $dashboard)
			{
				$app = $dashboard->getApp();
				if ($app && $dashboard->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
				{
					AppTable::uninstall($app->getId());
					AppTable::update($app->getId(), ['ACTIVE' => 'N', 'INSTALLED' => 'N']);
				}

				$dashboardId = $dashboard->getId();
				$deleteResult = $dashboard->delete();
				if (!$deleteResult->isSuccess())
				{
					Logger::logErrors($deleteResult->getErrors(), ['clearSupersetData, deleting dashboard ' . $dashboardId]);
				}
			}
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, deleting dashboards']);
		}

		try
		{
			$apps = AppTable::getList()->fetchCollection();
			foreach ($apps as $app)
			{
				if ($app->getCode() && MarketDashboardManager::isDatasetAppByAppCode($app->getCode()))
				{
					AppTable::uninstall($app->getId());
					AppTable::update($app->getId(), ['ACTIVE' => 'N', 'INSTALLED' => 'N']);
				}
			}
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, deleting dataset apps']);
		}

		try
		{
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
							$connection->query(
								sprintf('DROP TABLE IF EXISTS %s;', $connection->getSqlHelper()->quote($tableName))
							);
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
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, deleting datasets']);
		}

		try
		{
			foreach (SupersetUserTable::getList()->fetchCollection() as $user)
			{
				$userId = $user->getId();
				$userDeleteResult = $user->delete();
				if (!$userDeleteResult->isSuccess())
				{
					Logger::logErrors($userDeleteResult->getErrors(), ['clearSupersetData, deleting superset user ' . $userId]);
				}
			}
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, deleting superset users']);
		}

		try
		{
			Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_PERIOD_OPTION_NAME]);
			Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_START_OPTION_NAME]);
			Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_END_OPTION_NAME]);
			Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);
			Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_INCLUDE_LAST_FILTER_DATE_OPTION_NAME]);
			Option::delete('biconnector', ['name' => SystemDashboardManager::SYSTEM_DASHBOARDS_DELETED_CODES_OPTION]);
			Option::delete('biconnector', ['name' => '~superset_init_required_dataset_table_hash']);
			Option::delete('biconnector', ['name' => '~superset_init_required_dataset_last_attempt']);
			DatasetSettings::enableTypingForNewInstance();
			Option::delete('biconnector', ['name' => DataTimezone::OPTION_NAME]);

			\CUserOptions::DeleteOptionsByName('main.ui.filter', DashboardGrid::SUPERSET_DASHBOARD_GRID_ID);
			\CUserOptions::DeleteOptionsByName('main.ui.filter.presets', DashboardGrid::SUPERSET_DASHBOARD_GRID_ID);
			\CUserOptions::DeleteOptionsByName('biconnector', 'draft_guide');
			\CUserOptions::DeleteOptionsByName('biconnector', 'top_menu_dashboards');
			\CUserOptions::DeleteOptionsByName('biconnector', 'grid_pinned_dashboards');

			DomainLinkService::getInstance()->clearUnlinkedStatus();
			ConfigContainer::getConfigContainer()->clearLocalDataGeneration();
			Registrar::getRegistrar()->clear(__CLASS__ . '::' . __FUNCTION__);
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, deleting options']);
		}

		try
		{
			SupersetDashboardTagTable::deleteByFilter(['>ID' => 0]);
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, deleting dashboard tags']);
		}

		try
		{
			AccessInstaller::clearRelations();
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, clearing access relations']);
		}

		try
		{
			self::markSupersetInstanceExists(false);
			self::markSupersetInstanceSuspended(false);
			self::clearRebindRequired();
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], ['clearSupersetData, marking instance flags']);
		}
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
		$isResumeRequired = false;
		$isLocked = self::acquirePendingDeleteLock(__FUNCTION__);
		try
		{
			if ($isLocked)
			{
				$isResumeRequired = self::cancelPendingDelete();
			}

			\CAgent::RemoveAgent(Agent::class . '::deleteInstance();', 'biconnector');
			Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);
			self::clearPendingDeleteRetry();
		}
		finally
		{
			if ($isLocked)
			{
				self::releasePendingDeleteLock();
			}
		}

		if (!$isLocked)
		{
			self::schedulePendingDeleteRepair();
		}

		if ($isResumeRequired)
		{
			self::resumeSuperset(['reason' => self::FREEZE_REASON_PENDING_DELETE]);
		}

		self::silenceRebindDeleteRefusal();
	}

	/**
	 * Cancel pending deletion and immediately delete all data, starting fresh.
	 *
	 * @return void
	 */
	public static function resetSuperset(): void
	{
		$retryAgentName = Agent::class . '::deleteInstance();';
		$isLocked = self::acquirePendingDeleteLock(__FUNCTION__);
		try
		{
			\CAgent::RemoveAgent(Agent::class . '::pendingDeleteCheck();', 'biconnector');
			\CAgent::RemoveAgent(Agent::class . '::pendingDeleteNotify();', 'biconnector');
			\CAgent::RemoveAgent($retryAgentName, 'biconnector');
			Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);
			self::clearPendingDeleteRetry();
		}
		finally
		{
			if ($isLocked)
			{
				self::releasePendingDeleteLock();
			}
		}

		$outcome = self::getDeleteOutcome(self::deleteInstance());
		if ($outcome === DestructiveOperationOutcome::Undetermined)
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

		if ($outcome !== DestructiveOperationOutcome::Success)
		{
			return;
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
				self::withRebindRegistrationLock(static fn() => self::disableRebindPortal(), __FUNCTION__);

				return;
			}

			if (!self::isSupersetInstanceExists() && !self::isSupersetLoading())
			{
				if (self::getDeleteOutcome(self::deleteInstance()) !== DestructiveOperationOutcome::Success)
				{
					return;
				}

				self::setSupersetStatus(self::SUPERSET_STATUS_DOESNT_EXISTS);
				Registrar::getRegistrar()->clear(__CLASS__ . '::' . __FUNCTION__);

				return;
			}

			self::pendingDeleteInstance();
	}

	public static function checkRebindDelete(): string
	{
		$nextAgent = self::REBIND_DELETE_CHECK_AGENT;
		self::runUnderRebindRegistrationLock(
			static function () use (&$nextAgent): void {
				$nextAgent = self::stepRebindDeleteCheck();
			},
			0,
		);

		return $nextAgent;
	}

	private static function disableRebindPortal(): void
	{
		if (self::settleRebindDeleteCheckOnDisable())
		{
			return;
		}

		// Local portalId is detached in rebind state.
		// Pull portalId back from proxy so the real DELETE can target it.
		$response = IntegratorFactory::getInstance()->registerPortal();
		$portalId = (string)($response->getData()['portalId'] ?? '');

		// A reinstalled portal has no generation of its own: DELETE is addressed to the one of the answer,
		// an empty one included, as the service compares it with its own as is.
		$serverGeneration = (string)($response->getData()['generation'] ?? '');

		$operationId = '';
		$outcome = self::borrowRegistration(
			$portalId,
			$serverGeneration,
			static function () use (&$operationId): DestructiveOperationOutcome {
				$deleteResult = self::deleteInstance();
				$operationId = self::getDeleteOperationId($deleteResult);

				return self::getDeleteOutcome($deleteResult);
			},
		);

		if ($outcome === DestructiveOperationOutcome::Success)
		{
			self::completeRebindDisable();

			return;
		}

		if ($outcome === DestructiveOperationOutcome::Undetermined)
		{
			self::startRebindDeleteCheck($operationId, $portalId, $serverGeneration);

			return;
		}

		Agent::notifyDisableRefused();
	}

	private static function settleRebindDeleteCheckOnDisable(): bool
	{
		$check = self::readRebindDeleteCheck();
		if ($check === null || self::dropIrrelevantRebindDeleteCheck($check))
		{
			return false;
		}

		$outcome = self::resolveRebindDeleteCheck($check);
		if ($outcome === DestructiveOperationOutcome::Undetermined)
		{
			self::updateRebindDeleteCheck($check, ['notifyRefusal' => true]);

			return true;
		}

		self::dropRebindDeleteCheck();
		if ($outcome === DestructiveOperationOutcome::Success)
		{
			self::completeRebindDisable();

			return true;
		}

		return false;
	}

	private static function stepRebindDeleteCheck(): string
	{
		$check = self::readRebindDeleteCheck();
		if ($check === null || self::dropIrrelevantRebindDeleteCheck($check))
		{
			return '';
		}

		$outcome = self::resolveRebindDeleteCheck($check);
		if ($outcome === DestructiveOperationOutcome::Success)
		{
			self::dropRebindDeleteCheck();
			self::completeRebindDisable();

			return '';
		}

		if ($outcome === DestructiveOperationOutcome::Undetermined)
		{
			$attempt = $check['attempt'] + 1;
			if ($attempt < self::GENERATION_BACKFILL_ATTEMPT_LIMIT)
			{
				self::updateRebindDeleteCheck($check, ['attempt' => $attempt]);

				return self::REBIND_DELETE_CHECK_AGENT;
			}

			SupersetInitializerLogger::logErrors(
				[new Error('The superset service did not report the outcome of the deletion')],
				[
					'message' => 'the deletion of the instance of the rebind portal is left undetermined',
					'operationId' => $check['operationId'],
					'attempts' => $attempt,
				],
			);
		}

		self::refuseRebindDeleteCheck($check);

		return '';
	}

	private static function refuseRebindDeleteCheck(array $check): void
	{
		$currentCheck = self::readRebindDeleteCheck();
		self::dropRebindDeleteCheck();

		if (
			$currentCheck !== null
			&& $currentCheck['operationId'] === $check['operationId']
			&& $currentCheck['notifyRefusal']
		)
		{
			Agent::notifyDisableRefused();
		}
	}

	private static function resolveRebindDeleteCheck(array $check): DestructiveOperationOutcome
	{
		return self::borrowRegistration(
			$check['portalId'],
			$check['serverGeneration'],
			static function () use ($check): DestructiveOperationOutcome {
				$outcome = DestructiveOperationResolver::resolveOperationState(
					OperationIntentTable::OPERATION_DELETE,
					$check['operationId'],
				);
				if ($outcome === DestructiveOperationOutcome::Success)
				{
					self::forgetDeletedInstanceRegistration(self::class . '::checkRebindDelete');
				}

				return $outcome;
			},
		);
	}

	private static function dropIrrelevantRebindDeleteCheck(array $check): bool
	{
		$deletedStatuses = [self::SUPERSET_STATUS_DELETED, self::SUPERSET_STATUS_DOESNT_EXISTS];
		if (in_array(self::getSupersetStatus(), $deletedStatuses, true))
		{
			self::dropRebindDeleteCheck();

			return true;
		}

		$portalHost = self::getPortalHost();
		$reason = self::findRebindDeleteCheckObstacle($check, $portalHost);
		if ($reason === null)
		{
			return false;
		}

		self::dropRebindDeleteCheck();
		SupersetInitializerLogger::logWarning(
			[new Error('The portal has changed since the deletion of the instance of the rebind portal')],
			[
				'message' => 'the check of the deletion of the rebind portal is dropped',
				'reason' => $reason,
				'operationId' => $check['operationId'],
				'checkHost' => $check['host'],
				'portalHost' => $portalHost,
			],
		);

		return true;
	}

	private static function findRebindDeleteCheckObstacle(array $check, string $portalHost): ?string
	{
		$config = ConfigContainer::getConfigContainer();
		$pendingDeleteStatuses = [self::SUPERSET_STATUS_PENDING_DELETE, self::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED];

		return match (true)
		{
			SupersetHostMode::isSelfHosted() => 'the portal is self-hosted',
			!self::isRebindRequired() => 'the rebind is over',
			$config->getPortalId() !== '' || $config->isPortalIdVerified() => 'the portal is bound again',
			in_array(self::getSupersetStatus(), $pendingDeleteStatuses, true) => 'the deferred deletion has started',
			$check['portalId'] === '' => 'the check has no portal id',
			$check['host'] !== $portalHost => 'the host of the portal has changed',
			default => null,
		};
	}

	private static function borrowRegistration(
		string $portalId,
		string $serverGeneration,
		callable $request,
	): DestructiveOperationOutcome
	{
		$config = ConfigContainer::getConfigContainer();
		$config->beginRegistrationBorrow($portalId, $serverGeneration);

		$outcome = DestructiveOperationOutcome::Undetermined;
		try
		{
			$outcome = $request();
		}
		finally
		{
			$config->endRegistrationBorrow($outcome === DestructiveOperationOutcome::Success);
		}

		return $outcome;
	}

	public static function withRebindRegistrationLock(callable $action, string $actionName): bool
	{
		if (self::runUnderRebindRegistrationLock($action, self::REBIND_REGISTRATION_LOCK_WAIT))
		{
			return true;
		}

		SupersetInitializerLogger::logWarning(
			[new Error('The action is skipped as the registration of the portal is busy')],
			[
				'message' => 'the registration of the rebind portal is held by another process',
				'action' => $actionName,
				'waitSeconds' => self::REBIND_REGISTRATION_LOCK_WAIT,
			],
		);

		return false;
	}

	private static function runUnderRebindRegistrationLock(callable $action, int $waitSeconds): bool
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::REBIND_REGISTRATION_LOCK_NAME, $waitSeconds))
		{
			return false;
		}

		try
		{
			$action();
		}
		finally
		{
			$connection->unlock(self::REBIND_REGISTRATION_LOCK_NAME);
		}

		return true;
	}

	private static function completeRebindDisable(): void
	{
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
	}

	private static function startRebindDeleteCheck(string $operationId, string $portalId, string $serverGeneration): void
	{
		if ($operationId === '' || $portalId === '')
		{
			return;
		}

		self::saveRebindDeleteCheck([
			'operationId' => $operationId,
			'portalId' => $portalId,
			'serverGeneration' => $serverGeneration,
			'host' => self::getPortalHost(),
			'attempt' => 0,
			'notifyRefusal' => true,
		]);
		self::scheduleHourlySeriesAgent(self::REBIND_DELETE_CHECK_AGENT);
	}

	private static function silenceRebindDeleteRefusal(): void
	{
		if (self::readRebindDeleteCheck() === null)
		{
			return;
		}

		self::withRebindRegistrationLock(
			static function (): void {
				$check = self::readRebindDeleteCheck();
				if ($check !== null)
				{
					self::updateRebindDeleteCheck($check, ['notifyRefusal' => false]);
				}
			},
			'onEnableBiBuilderTool',
		);
	}

	private static function updateRebindDeleteCheck(array $check, array $changes): void
	{
		$currentCheck = self::readRebindDeleteCheck();
		if ($currentCheck === null || $currentCheck['operationId'] !== $check['operationId'])
		{
			return;
		}

		self::saveRebindDeleteCheck($changes + $currentCheck);
	}

	private static function readRebindDeleteCheck(): ?array
	{
		$check = ServiceLocator::getInstance()
			->get(PersistentStorageInterface::class)
			->get(self::REBIND_DELETE_CHECK_KEY)
		;
		if (!is_array($check) || !is_string($check['operationId'] ?? null) || $check['operationId'] === '')
		{
			return null;
		}

		return [
			'operationId' => $check['operationId'],
			'portalId' => (string)($check['portalId'] ?? ''),
			'serverGeneration' => (string)($check['serverGeneration'] ?? ''),
			'host' => (string)($check['host'] ?? ''),
			'attempt' => (int)($check['attempt'] ?? 0),
			'notifyRefusal' => (bool)($check['notifyRefusal'] ?? true),
		];
	}

	private static function saveRebindDeleteCheck(array $check): void
	{
		ServiceLocator::getInstance()
			->get(PersistentStorageInterface::class)
			->set(self::REBIND_DELETE_CHECK_KEY, $check, self::REBIND_DELETE_CHECK_TTL)
		;
	}

	private static function dropRebindDeleteCheck(): void
	{
		ServiceLocator::getInstance()->get(PersistentStorageInterface::class)->delete(self::REBIND_DELETE_CHECK_KEY);
	}

	private static function getPortalHost(): string
	{
		$host = defined('BX24_HOST_NAME') ? (string)BX24_HOST_NAME : Option::get('main', 'server_name');
		if ($host === '')
		{
			$host = (string)SiteTable::getDefaultSite(['SERVER_NAME'])?->getServerName();
		}

		return mb_strtolower((string)preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host));
	}
}
