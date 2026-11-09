<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\AccessDeniedException;
use Bitrix\AiAssistant\Exceptions\DefinitionException;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Exceptions\ValidationException;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\ActAsFailure;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\RateLimit\BiToolsRateLimiter;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DashboardGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Everything the two BI tool hierarchies share: availability of the BI service,
 * dashboard loading with its access and state gates, and the refusal factories.
 *
 * The hierarchies differ only in their contract ({@see BaseBiTool} on the plain
 * one and {@see BaseSubjectAwareBiTool} on the subject-aware one), so this is the
 * single place the shared behaviour lives; no tool re-implements any of it.
 */
trait BiToolBehaviour
{
	private const UNAVAILABLE_DASHBOARD_MESSAGE = 'Dashboard is temporarily unavailable. Try again in a moment, or pick another report.';
	// Longest refusal the API composes is a couple of sentences plus a value list.
	private const MAX_REFUSAL_LENGTH = 500;

	private const SQL_LAB_QUERY_FAULT_CODES = ['SQLLAB_INVALID_QUERY', 'SQLLAB_ACCESS_DENIED'];

	// {@see Integrator\Sender::parseJsonResponse()} wraps a body it could not
	// decode into an error of its own, carrying that body verbatim.
	private const UNPARSED_RESPONSE_PREFIX = 'Could not parse server response';

	/**
	 * Charts, datasets, BI tables and SQL Lab have no dashboard to fall back to,
	 * so they report the failure in terms of the BI service instead.
	 */
	private const UNAVAILABLE_BI_MESSAGE = 'The BI request could not be completed right now: the BI service may be busy or still syncing. Try again in a moment.';

	// The refusal type depends on a flag decided per user, and refusals are built without one at hand.
	private int $callerUserId = 0;

	/**
	 * Both BI tool hierarchies count into the same window on the same user, so
	 * read and write calls share one budget regardless of the branch. Only the
	 * `run()` signatures of the two contracts are incompatible, so the check
	 * itself lives here and each runner calls it as its first line.
	 *
	 * @throws McpException When the per-user rate limit is exceeded.
	 */
	protected function enforceRateLimit(int $userId): void
	{
		$limit = (new BiToolsRateLimiter())->consume($userId);
		if ($limit['allowed'])
		{
			return;
		}

		throw new McpException(
			'Rate limit exceeded: не более ' . BiToolsRateLimiter::MAX_REQUESTS
			. ' запросов BI-инструментов в минуту. Повтори через ' . $limit['retryAfter'] . ' сек.',
		);
	}

	/**
	 * How far this tool reaches. Declared once per tool and read from here by
	 * everything that needs it, so no catalogue or instruction restates it.
	 */
	abstract public function getImpact(): OperationImpact;

	/**
	 * Visibility widened from the tool contracts, where the method is protected:
	 * the declared impact is read directly, without composing the full tool
	 * description. Both hierarchies answer the same way.
	 */
	public function getAnnotations(): ?object
	{
		return $this->getImpact()->toAnnotations();
	}

	/**
	 * The Superset user the tool acts for; reads that need no person without the axis take findActAsUser().
	 * While the table permission axis is on, the request runs as that person and there is no fallback to
	 * the service account: a request without a person must not read BI data on behalf of the administrator.
	 * Without the axis nothing runs as the person: the user keys the limiter and the audit of SQL Lab and owns
	 * a created dataset, as before act-as, and a request without a person is the administrator.
	 *
	 * @throws McpException When the caller is unknown or has no Superset user.
	 */
	protected static function requireSupersetUser(int $userId): User
	{
		$repository = new SupersetUserRepository();
		if ($userId <= 0)
		{
			$user = Feature::isTablePermissionsEnabled() ? null : $repository->getAdmin();
		}
		else
		{
			$user = $repository->getById($userId);
		}

		if ($user === null)
		{
			throw new McpException('A Superset user is required to read BI data.');
		}

		return $user;
	}

	/**
	 * The person a read of the tool runs as. While the table permission axis is on it is required, see
	 * requireSupersetUser(). Without the axis there is none: the read goes under the service account and needs
	 * no Superset user, as before act-as.
	 *
	 * @throws McpException When the axis is on and the caller is unknown or has no Superset user.
	 */
	protected static function findActAsUser(int $userId): ?User
	{
		return Feature::isTablePermissionsEnabled() ? self::requireSupersetUser($userId) : null;
	}

	protected static function isBiAvailable(): bool
	{
		if (!Feature::isBitrixGptBiConstructorEnabled())
		{
			return false;
		}

		if (!Loader::includeModule('biconnector'))
		{
			return false;
		}

		if (!Feature::isBuilderEnabled())
		{
			return false;
		}

		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		)
		{
			return false;
		}

		if (!DomainLinkService::getInstance()->isLinked())
		{
			return false;
		}

		return true;
	}

	protected function getAccessController(int $userId): AccessController
	{
		return new AccessController($userId);
	}

	/**
	 * Object-level write scope for charts. A chart may be modified or deleted
	 * only if the user has EDIT rights on at least one dashboard that uses it:
	 * the same rule the web UI applies, where charts are managed through their
	 * dashboards.
	 *
	 * `$chartDashboards` is the raw Superset `dashboards` list from
	 * getChartById(): each entry's `id` is the dashboard's Superset EXTERNAL_ID,
	 * translated here to the local {@see SupersetDashboardTable} PK before the ACL
	 * check.
	 *
	 * Denies (throws) an orphan chart with no dashboards, dashboards not known
	 * locally, or none the user can edit.
	 *
	 * @param array $chartDashboards Raw Superset dashboards from the chart payload.
	 * @throws McpException When the user cannot edit any consumer dashboard.
	 */
	protected function assertChartEditableByDashboards(int $userId, array $chartDashboards): void
	{
		$accessController = $this->getAccessController($userId);

		$externalIds = [];
		foreach ($chartDashboards as $dashboard)
		{
			if (!is_array($dashboard))
			{
				continue;
			}

			$externalId = (int)($dashboard['id'] ?? 0);
			if ($externalId > 0)
			{
				$externalIds[$externalId] = $externalId;
			}
		}

		if (empty($externalIds))
		{
			throw new McpException(
				'You can edit this chart only if you have edit rights on at least one dashboard that uses it.',
			);
		}

		// One query for all consumer dashboards instead of one per dashboard.
		$rows = SupersetDashboardTable::getList([
			'select' => ['ID', 'EXTERNAL_ID'],
			'filter' => ['=EXTERNAL_ID' => array_values($externalIds)],
		])->fetchAll();

		$localIdByExternalId = [];
		foreach ($rows as $row)
		{
			$externalId = (int)($row['EXTERNAL_ID'] ?? 0);
			$localId = (int)($row['ID'] ?? 0);
			if ($externalId > 0 && $localId > 0)
			{
				$localIdByExternalId[$externalId] = $localId;
			}
		}

		foreach ($externalIds as $externalId)
		{
			$localId = $localIdByExternalId[$externalId] ?? 0;
			if ($localId <= 0)
			{
				continue;
			}

			$accessItem = DashboardAccessItem::createFromId($localId);
			if ($accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem))
			{
				return;
			}
		}

		throw new McpException(
			'You can edit this chart only if you have edit rights on at least one dashboard that uses it.',
		);
	}

	/**
	 * Object-level write scope for datasets. A dataset may be modified or deleted
	 * only by one of its owners. Ownership is compared on CLIENT_ID, the
	 * cross-mode (box/cloud) user key: `$datasetOwners` is the owners list from
	 * getDatasetById() already mapped to CLIENT_IDs, and the current user's
	 * CLIENT_ID is resolved from the local biconnector user mapping.
	 *
	 * Denies (throws) a dataset without owners, an unknown current identity, or a
	 * user who is not among the owners.
	 *
	 * @param array $datasetOwners Dataset owners as CLIENT_ID strings.
	 * @throws McpException When the current user does not own the dataset.
	 */
	protected function assertDatasetOwned(int $userId, array $datasetOwners): void
	{
		$row = SupersetUserTable::getRow([
			'select' => ['CLIENT_ID'],
			'filter' => ['=USER_ID' => $userId],
		]);
		$clientId = (string)($row['CLIENT_ID'] ?? '');

		$owners = [];
		foreach ($datasetOwners as $owner)
		{
			if (is_string($owner) && $owner !== '')
			{
				$owners[] = $owner;
			}
		}

		if ($clientId === '' || !in_array($clientId, $owners, true))
		{
			throw new McpException('You can modify or delete only datasets you own.');
		}
	}

	/**
	 * Returns a tool argument as it was sent by the client
	 *
	 * @param string $name Argument name to extract.
	 * @param array $args filtered arguments passed to the tool.
	 * @return mixed Raw client value, or the filtered value as a fallback.
	 */
	protected function getRawArgument(string $name, array $args): mixed
	{
		$request = Application::getInstance()->getContext()->getRequest();

		$rawArgs = $request->getJsonList()->getRaw('arguments');
		if (is_array($rawArgs) && array_key_exists($name, $rawArgs))
		{
			return $rawArgs[$name];
		}

		try
		{
			$raw = $request->getInput();
			if (is_string($raw) && $raw !== '')
			{
				$decoded = \Bitrix\Main\Web\Json::decode($raw);
				$bodyArgs = $decoded['arguments'] ?? $decoded['params']['arguments'] ?? null;
				if (is_array($bodyArgs) && array_key_exists($name, $bodyArgs))
				{
					return $bodyArgs[$name];
				}
			}
		}
		catch (\Throwable)
		{
		}

		return $args[$name] ?? null;
	}

	protected function loadViewableDashboard(int $dashboardId, int $userId): Result
	{
		$result = new Result();

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			return $result->addError(new Error('Dashboard not found.', 'dashboard_not_found'));
		}

		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			return $result->addError(new Error('Access denied.', FailureCode::AccessDenied->value));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	protected function loadReadyDashboard(int $dashboardId, int $userId): Result
	{
		$result = new Result();

		$instanceStatus = SupersetInitializer::getSupersetStatus();
		if ($instanceStatus !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return $this->handleInstanceNotReady($instanceStatus, $dashboardId, $userId);
		}

		$accessResult = $this->loadViewableDashboard($dashboardId, $userId);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}
		$dashboard = $accessResult->getData()['dashboard'];

		$status = $dashboard->getStatus();
		$installableStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED,
			SupersetDashboardTable::DASHBOARD_STATUS_FAILED,
		];
		if (in_array($status, $installableStatuses, true))
		{
			Application::getInstance()->addBackgroundJob(static function () use ($dashboardId) {
				MarketDashboardManager::getInstance()->reinstallDashboard($dashboardId);
			});

			return $result->addError(new Error(
				'Dashboard is preparing, first open takes a moment. Try again shortly.',
				'dashboard_preparing',
			));
		}

		if ($status === SupersetDashboardTable::DASHBOARD_STATUS_LOAD)
		{
			return $result->addError(new Error(
				'Dashboard is preparing, first open takes a moment. Try again shortly.',
				'dashboard_preparing',
			));
		}

		$readyStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_READY,
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
		];
		if (!in_array($status, $readyStatuses, true))
		{
			$detail = 'Dashboard ' . $dashboardId . ' status not ready: ' . $status;
			AiToolsLogger::logErrors(
				[new Error($detail)],
				['stage' => 'load_dashboard.status', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);

			$message = self::appendDebugDetail(self::UNAVAILABLE_DASHBOARD_MESSAGE, $detail);

			return $result->addError(new Error($message, 'dashboard_unavailable'));
		}

		if (!$dashboard->getExternalId())
		{
			$detail = 'Dashboard ' . $dashboardId . ' has no external id (not synced yet)';
			AiToolsLogger::logErrors(
				[new Error($detail)],
				['stage' => 'load_dashboard.no_external_id', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);

			$message = self::appendDebugDetail(self::UNAVAILABLE_DASHBOARD_MESSAGE, $detail);

			return $result->addError(new Error($message, 'dashboard_unavailable'));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	/**
	 * Adapts the shared write guard to the legacy MCP error codes.
	 */
	protected function loadDashboardForWrite(int $dashboardId, int $userId): Result
	{
		$guardResult = ServiceLocator::getInstance()->get(DashboardGuard::class)
			->forWriteForUser($dashboardId, $userId)
		;
		if ($guardResult->isSuccess())
		{
			return $guardResult;
		}

		$result = new Result();
		foreach ($guardResult->getErrors() as $error)
		{
			$code = match ($error->getCode()) {
				FailureCode::FeatureDisabled->value => 'feature_disabled',
				FailureCode::NotFound->value => 'dashboard_not_found',
				FailureCode::NotCustom->value => 'not_custom',
				FailureCode::Unavailable->value => 'dashboard_unavailable',
				default => $error->getCode(),
			};
			$result->addError(new Error($error->getMessage(), $code));
		}

		return $result;
	}

	private function handleInstanceNotReady(string $instanceStatus, int $dashboardId, int $userId): Result
	{
		$result = new Result();

		if ($instanceStatus === SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			SupersetInitializer::startupSuperset();
		}

		if (in_array($instanceStatus, [
			SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
			SupersetInitializer::SUPERSET_STATUS_LOAD,
		], true))
		{
			return $result->addError(new Error(
				'BI is preparing, this can take a moment on first use. Try again shortly.',
				'bi_preparing',
			));
		}

		if ($instanceStatus === SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED)
		{
			return $result->addError(new Error(
				'Maximum number of BI Builder instances reached for this license key. '
				. 'Disable BI Builder on any other Bitrix24 that uses the same license key, '
				. 'then try again.',
				'bi_limit_exceeded',
			));
		}

		$detail = 'Instance status ' . $instanceStatus . ' for dashboard ' . $dashboardId;
		AiToolsLogger::logErrors(
			[new Error($detail)],
			[
				'stage' => 'load_dashboard.instance_status',
				'dashboard_id' => $dashboardId,
				'user_id' => $userId,
				'instance_status' => $instanceStatus,
			],
		);

		$message = self::appendDebugDetail('BI is temporarily unavailable. Try again later.', $detail);

		return $result->addError(new Error($message, 'bi_unavailable'));
	}

	/**
	 * Translate the first {@see Error} of a failing Result into an McpException
	 * with structured payload (if any) appended as JSON. Throw sites stay in
	 * `executeStructured()`: helpers below the tool layer return Results.
	 *
	 * With BuilderMcpAlignment a refusal by access or by argument keeps its own type, so the
	 * external MCP channel passes its reason on instead of collapsing it; the text is the same.
	 */
	protected function toMcpException(Result $result): DefinitionException
	{
		$errors = $result->getErrors();
		$error = $errors[0] ?? new Error('The BI operation failed for an unknown reason. Try again; if it keeps failing, report it.');
		$message = $error->getMessage();
		$payload = $error->getCustomData();
		if (is_array($payload) && !empty($payload))
		{
			$message .= '. ' . json_encode($payload, JSON_UNESCAPED_UNICODE);
		}

		$typedException = match ($error->getCode()) {
			FailureCode::AccessDenied->value => new AccessDeniedException($message),
			FailureCode::InvalidArgument->value => new ValidationException($message),
			default => null,
		};
		if (
			$typedException === null
			|| !(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $this->callerUserId)
		)
		{
			return new McpException($message);
		}

		return $typedException;
	}

	/**
	 * Translate a failed Integrator response for the agent. A `400` carries its
	 * reason through: what has to change is the request itself, so answering
	 * "try again later" both hides the cause and advises the wrong action.
	 * Everything else (transport, instance status, upstream failure) stays
	 * masked by {@see unavailableDashboardException()}. `403`/`404` are masked
	 * too: access and existence are decided locally before any Integrator call,
	 * so a remote one speaks about our sync state, not about the user's request.
	 */
	protected static function integratorFailureException(
		IntegratorResponse $response,
		array $context,
	): McpException
	{
		$errors = $response->getErrors();
		$actAsMessage = ActAsFailure::findMessage($errors);
		if ($actAsMessage !== null)
		{
			return self::actAsFailedException($actAsMessage, $errors, $context);
		}

		// A response can fail by carrying no data at all, with no error attached.
		$message = trim(($errors[0] ?? null)?->getMessage() ?? '');
		if (
			$response->getStatus() !== IntegratorResponse::STATUS_BAD_REQUEST
			|| !self::isRecognizedRefusal($message)
		)
		{
			return self::unavailableDashboardException($errors, $context);
		}

		AiToolsLogger::logWarning($errors, $context);

		return new McpException($message);
	}

	/**
	 * Translate a failed read of dashboard data (overview, filter values). Read as the person, a `403`/`404`
	 * means Superset does not let that person to the dashboard: the portal has let them to it already, so
	 * it is a refusal to report, not a dashboard to wait for. Without act-as the general translation stays.
	 */
	protected static function dashboardReadFailureException(
		IntegratorResponse $response,
		array $context,
	): McpException
	{
		$errors = $response->getErrors();
		if (
			ActAsFailure::findMessage($errors) === null
			&& ActAsFailure::isDashboardRefused($response)
			&& Feature::isTablePermissionsEnabled()
		)
		{
			return self::actAsFailedException(ActAsFailure::DASHBOARD_ACCESS_DENIED_MESSAGE, $errors, $context);
		}

		return self::integratorFailureException($response, $context);
	}

	/**
	 * Whether a `400` text is a refusal formulated by the API rather than an
	 * arbitrary response body. Anything the remote side did not phrase for the
	 * agent, be it a proxy error page or an unparsed body wrapped by the
	 * Integrator, lands verbatim in the model context, so it is both noise and an indirect
	 * prompt-injection channel, and gets masked instead.
	 */
	private static function isRecognizedRefusal(string $message): bool
	{
		if ($message === '' || mb_strlen($message) > self::MAX_REFUSAL_LENGTH)
		{
			return false;
		}

		if (str_contains($message, '<'))
		{
			return false;
		}

		return !str_starts_with($message, self::UNPARSED_RESPONSE_PREFIX);
	}

	/**
	 * Wrap an Integrator failure in a generic agent-facing message + a detailed
	 * event log entry. Real cause never leaks to the LLM context.
	 *
	 * Logged at WARNING, not ERROR: a failure to read data from Superset is not
	 * something this module can fix (it is a downstream/transient condition, e.g.
	 * a just-installed report whose charts are not queryable yet), so it must not
	 * raise error-level noise. Genuine module-side problems (bad dashboard status,
	 * instance error) are still logged as errors from their own call sites.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	protected static function unavailableDashboardException(array $errors, array $context): McpException
	{
		return self::unavailableException(self::UNAVAILABLE_DASHBOARD_MESSAGE, $errors, $context);
	}

	/**
	 * Same as {@see self::unavailableDashboardException()} for tools that operate
	 * on charts, datasets, BI tables or SQL Lab, where a dashboard-worded message
	 * would misdirect the agent.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	protected static function unavailableBiException(array $errors, array $context): McpException
	{
		$actAsMessage = ActAsFailure::findMessage($errors);
		if ($actAsMessage !== null)
		{
			return self::actAsFailedException($actAsMessage, $errors, $context);
		}

		return self::unavailableException(self::UNAVAILABLE_BI_MESSAGE, $errors, $context);
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private static function unavailableException(string $message, array $errors, array $context): McpException
	{
		AiToolsLogger::logWarning($errors, $context);

		if (self::isDebugLogEnabled())
		{
			$details = implode('; ', array_map(static fn(Error $e): string => $e->getMessage(), $errors));
			$message .= ' | bi_debug: ' . $details . ' | ctx: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
		}

		return new McpException($message);
	}

	/**
	 * SQL Lab errors (syntax, timeout, table access reported by Superset) are
	 * user-actionable: the agent is expected to fix and retry its query. Unlike
	 * {@see self::unavailableDashboardException()} the real Superset message is
	 * surfaced to the agent, while still being logged.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	protected static function sqlLabException(array $errors, array $context): McpException
	{
		$actAsMessage = ActAsFailure::findMessage($errors);
		if ($actAsMessage !== null)
		{
			return self::actAsFailedException($actAsMessage, $errors, $context);
		}

		if (self::isQueryFault($errors))
		{
			AiToolsLogger::logWarning($errors, $context);
		}
		else
		{
			AiToolsLogger::logErrors($errors, $context);
		}

		$message = implode('; ', array_map(static fn(Error $e): string => self::describeSqlLabError($e), $errors));
		if ($message === '')
		{
			$message = 'SQL execution failed and Superset returned no error text. Check the query syntax and table/column names, then retry.';
		}

		return new McpException($message);
	}

	/**
	 * A query the agent wrote wrong or over tables closed to the person: the agent fixes it, the service is fine.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private static function isQueryFault(array $errors): bool
	{
		if ($errors === [])
		{
			return false;
		}

		foreach ($errors as $error)
		{
			if (!in_array($error->getCode(), self::SQL_LAB_QUERY_FAULT_CODES, true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The public error code comes with a stable message; the verbatim Superset text travels
	 * in customData and only the AI channel unwraps it.
	 */
	private static function describeSqlLabError(Error $error): string
	{
		$customData = $error->getCustomData();
		$detail = is_array($customData) ? ($customData['detail'] ?? null) : null;
		if (!is_string($detail) || $detail === '')
		{
			return $error->getMessage();
		}

		return $error->getMessage() . ': ' . $detail;
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private static function actAsFailedException(string $message, array $errors, array $context): McpException
	{
		AiToolsLogger::logErrors($errors, $context);

		return new McpException($message);
	}

	private static function isDebugLogEnabled(): bool
	{
		return Option::get('biconnector', 'bgpt_bi_expand_log', 'N') === 'Y';
	}

	private static function appendDebugDetail(string $message, string $detail): string
	{
		if (self::isDebugLogEnabled())
		{
			$message .= ' | bi_debug: ' . $detail;
		}

		return $message;
	}

	/**
	 * @throws McpException When the biconnector module cannot be included.
	 */
	protected static function requireBiconnectorModule(): void
	{
		if (!Loader::includeModule('biconnector'))
		{
			throw new McpException('BI Builder is not available on this portal.');
		}
	}
}
