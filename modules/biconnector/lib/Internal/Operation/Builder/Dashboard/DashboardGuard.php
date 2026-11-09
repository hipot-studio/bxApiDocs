<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\Model\DashboardAclBatch;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Loads a dashboard for a builder operation with the checks of the MCP tool loaders
 * (BiToolBehaviour::loadViewableDashboard() and loadDashboardForWrite()), refusing with
 * the same texts. On success getData()['dashboard'] holds the SupersetDashboard.
 * forView() also refuses, as the web does, a Market dashboard without the Market subscription
 * and a dashboard closed on the plan of the portal.
 *
 * Only the loader level is repeated. The entry point stays responsible for:
 * - the availability gates of BaseBiTool::canRun() / BiToolBehaviour::isBiAvailable():
 *   Feature::isBitrixGptBiConstructorEnabled(), the crm_bi tool switched off by the administrator
 *   in ToolsManager, DomainLinkService::isLinked();
 * - its rate limiter;
 * - ACTION_BIC_DASHBOARD_DELETE with DashboardDeleteRule for deletion, forWrite() checks EDIT only;
 * - taking Actor::$userId from the authenticated context (CurrentUser, REST auth),
 *   never from request parameters.
 */
final class DashboardGuard
{
	private const UNAVAILABLE_DASHBOARD_MESSAGE =
		'Dashboard is temporarily unavailable. Try again in a moment, or pick another report.'
	;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;
	/** @var \Closure(string): bool */
	private readonly \Closure $isAvailableBySubscription;
	/** @var \Closure(string): bool */
	private readonly \Closure $isAvailableOnPlan;
	private readonly bool $usesDefaultPolicies;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 * @param (\Closure(string): bool)|null $isAvailableBySubscription Takes the type of the dashboard.
	 * @param (\Closure(string): bool)|null $isAvailableOnPlan Takes the APP_ID of the dashboard.
	 */
	public function __construct(
		?\Closure $accessControllerFactory = null,
		?\Closure $isAvailableBySubscription = null,
		?\Closure $isAvailableOnPlan = null,
	)
	{
		$this->usesDefaultPolicies =
			$accessControllerFactory === null
			&& $isAvailableBySubscription === null
			&& $isAvailableOnPlan === null
		;
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
		$this->isAvailableBySubscription = $isAvailableBySubscription
			?? static fn(string $type, ?\Closure $observe = null): bool => MarketAccessManager::getInstance()
				->isDashboardAvailableByType($type, $observe)
		;
		$this->isAvailableOnPlan = $isAvailableOnPlan
			?? static fn(string $appId): bool => DashboardTariffConfigurator::isAvailableDashboard($appId)
		;
	}

	public function forView(int $dashboardId, Actor $actor): Result
	{
		return $this->loadForView($dashboardId, $actor);
	}

	/**
	 * @internal Each outer entry is one external mapping chunk; its ID order is retained.
	 * @param iterable<iterable<int>> $mappedChunks
	 */
	public function hasViewableCandidates(iterable $mappedChunks, Actor $actor): bool
	{
		$batch = $this->usesDefaultPolicies ? new DashboardAclBatch($actor->userId) : null;
		$firstCandidate = true;
		foreach ($mappedChunks as $mappedIds)
		{
			$ids = [];
			foreach ($mappedIds as $id)
			{
				$ids[] = $id;
				if ($firstCandidate || count($ids) === DashboardAclBatch::CHUNK_SIZE)
				{
					$firstCandidate = false;
					if ($this->hasViewableChunk($ids, $actor, $batch))
					{
						return true;
					}
					$ids = [];
				}
			}
			if ($ids !== [] && $this->hasViewableChunk($ids, $actor, $batch))
			{
				return true;
			}
		}

		return false;
	}

	private function hasViewableChunk(array $ids, Actor $actor, ?DashboardAclBatch $batch): bool
	{
		foreach ($ids as $offset => $id)
		{
			$canBatch = $batch?->canBatch() ?? false;
			$scanMissing = $batch?->canScanMissing() ?? false;
			if ($canBatch || $scanMissing)
			{
				if (!$batch->contains($id))
				{
					$batch->loadRows(array_slice($ids, $offset));
				}
				$fields = $batch->getRow($id);
				if ($fields === null)
				{
					continue;
				}
				if ($canBatch)
				{
					if ($this->finishView($id, $actor, $fields, $batch)->isSuccess())
					{
						return true;
					}

					continue;
				}

				// A missing-only scan must not preload the first real check's future rows.
				$batch->observeNext();
			}
			$activeBatch = $batch !== null && !$batch->isLegacy() ? $batch : null;
			if ($this->loadForView($id, $actor, $activeBatch)->isSuccess())
			{
				return true;
			}
		}
		$batch?->discardRows();

		return false;
	}

	private function loadForView(int $dashboardId, Actor $actor, ?DashboardAclBatch $batch = null): Result
	{
		$result = new Result();

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			$batch?->observeMissing();

			return $result->addError(new Error('Dashboard not found.', FailureCode::NotFound->value));
		}

		return $this->finishView($dashboardId, $actor, $dashboard, $batch);
	}

	private function finishView(
		int $dashboardId,
		Actor $actor,
		SupersetDashboard|array $dashboard,
		?DashboardAclBatch $batch = null,
	): Result
	{
		$result = new Result();
		$fields = is_array($dashboard) ? $dashboard : null;
		if (!$this->canAct(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboardId, $actor->userId, $batch, $fields))
		{
			return $result->addError(new Error('Access denied.', FailureCode::AccessDenied->value));
		}
		if ($batch?->isLegacy())
		{
			$batch = null;
		}

		$type = $fields !== null ? $fields['TYPE'] : $dashboard->getType();
		$batch?->beforeSubscription($type);
		if ($batch?->isLegacy())
		{
			$batch = null;
		}
		$subscriptionAvailable = $batch !== null
			? ($this->isAvailableBySubscription)($type, $batch->observeMarketCall(...))
			: ($this->isAvailableBySubscription)($type)
		;
		$batch?->afterSubscription($type);
		if ($batch?->isLegacy())
		{
			$batch = null;
		}
		if (!$subscriptionAvailable)
		{
			return $result->addError(new Error(
				'Market dashboard is unavailable: the Bitrix24 Market subscription has expired.',
				FailureCode::AccessDenied->value,
			));
		}

		$appId = (string)($fields !== null ? $fields['APP_ID'] : $dashboard->getAppId());
		if ($appId !== '')
		{
			$isSystem = $batch !== null && MarketDashboardManager::isSystemAppByAppCode($appId);
			if ($isSystem)
			{
				$batch->beforeSystemPlan();
			}
			$planAvailable = ($this->isAvailableOnPlan)($appId);
			if ($isSystem)
			{
				$batch->afterSystemPlan();
			}
			if (!$planAvailable)
			{
				return $result->addError(new Error(
					'Dashboard is not available on your plan.',
					FailureCode::AccessDenied->value,
				));
			}
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	public function forWrite(int $dashboardId, Actor $actor): Result
	{
		return $this->forWriteForUser($dashboardId, $actor->userId);
	}

	public function forWriteForUser(int $dashboardId, int $userId): Result
	{
		$result = new Result();

		if (!Feature::isBuilderEnabled())
		{
			return $result->addError(new Error(
				'BI Builder feature is not enabled on this portal.',
				FailureCode::FeatureDisabled->value,
			));
		}

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			return $result->addError(new Error('Dashboard not found.', FailureCode::NotFound->value));
		}

		if ($dashboard->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			return $result->addError(new Error(
				'Only custom dashboards can be updated.',
				FailureCode::NotCustom->value,
			));
		}

		$status = $dashboard->getStatus();
		$editableStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_READY,
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
		];
		if (!in_array($status, $editableStatuses, true))
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' status not editable: ' . $status)],
				[
					'stage' => 'load_dashboard_for_write.status',
					'dashboard_id' => $dashboardId,
					'user_id' => $userId,
				],
			);

			return $result->addError(new Error(self::UNAVAILABLE_DASHBOARD_MESSAGE, FailureCode::Unavailable->value));
		}

		if (!$this->canAct(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $dashboardId, $userId))
		{
			return $result->addError(new Error('Access denied.', FailureCode::AccessDenied->value));
		}

		if (!$dashboard->getExternalId())
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' has no external id (not synced yet)')],
				[
					'stage' => 'load_dashboard_for_write.no_external_id',
					'dashboard_id' => $dashboardId,
					'user_id' => $userId,
				],
			);

			return $result->addError(new Error(self::UNAVAILABLE_DASHBOARD_MESSAGE, FailureCode::Unavailable->value));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	private function canAct(
		string $action,
		int $dashboardId,
		int $userId,
		?DashboardAclBatch $batch = null,
		?array $fields = null,
	): bool
	{
		$accessController = ($this->accessControllerFactory)($userId);
		$item = $fields !== null
			? DashboardAccessItem::createFromArray($fields)
			: DashboardAccessItem::createFromId($dashboardId)
		;
		if ($batch !== null && !$batch->isLegacy())
		{
			return $accessController->checkWithDashboardBatch($item, $batch);
		}

		return $accessController->check($action, $item);
	}
}
