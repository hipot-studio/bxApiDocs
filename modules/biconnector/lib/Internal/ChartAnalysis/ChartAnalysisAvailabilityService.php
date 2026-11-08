<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\ChartAnalysis;

use Bitrix\AiAssistant\Service\GuardService;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupSubscriptionService;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\BitrixGptChat;
use Bitrix\BIConnector\Public\Dto\ChartAnalysisAvailability;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class ChartAnalysisAvailabilityService
{
	private readonly \Closure $isFlagEnabled;
	private readonly \Closure $isCloudPortal;
	private readonly \Closure $isBuilderEnabled;
	private readonly \Closure $isBiReady;
	private readonly \Closure $isBitrixGptAvailable;
	private readonly \Closure $isAgreementAccepted;
	private readonly \Closure $canView;
	private readonly \Closure $isToolEnabled;
	private readonly \Closure $isAvailableByPlan;

	private ?ChartAnalysisAvailability $portalAvailability = null;
	private ?int $portalUserId = null;

	public function __construct(
		private readonly DigestSetupSubscriptionService $subscriptionService = new DigestSetupSubscriptionService(),
		?\Closure $isFlagEnabled = null,
		?\Closure $isCloudPortal = null,
		?\Closure $isBuilderEnabled = null,
		?\Closure $isBiReady = null,
		?\Closure $isBitrixGptAvailable = null,
		?\Closure $isAgreementAccepted = null,
		?\Closure $canView = null,
		?\Closure $isToolEnabled = null,
		?\Closure $isAvailableByPlan = null,
	)
	{
		$this->isFlagEnabled = $isFlagEnabled ?? static fn(): bool => Feature::isChartAnalysisEnabled();
		$this->isCloudPortal = $isCloudPortal
			?? static fn(): bool => Loader::includeModule('bitrix24') && SupersetHostMode::isCloud();
		$this->isBuilderEnabled = $isBuilderEnabled ?? static fn(): bool => Feature::isBuilderEnabled();
		$this->isBiReady = $isBiReady ?? static fn(): bool => SupersetInitializer::isSupersetReady();
		$this->isBitrixGptAvailable = $isBitrixGptAvailable ?? static fn(): bool => BitrixGptChat::isAvailable();
		$this->isAgreementAccepted = $isAgreementAccepted ?? static function(): bool {
			if (!Loader::includeModule('aiassistant'))
			{
				return false;
			}

			// The guard answers "accepted" outside the by zone, so only by without the agreement is refused.
			$guard = ServiceLocator::getInstance()->get(GuardService::class);

			return $guard instanceof GuardService && $guard->isAgreementAccepted();
		};
		$this->canView = $canView ?? static fn(int $userId, Model\Dashboard $dashboard): bool =>
			AccessController::getInstance($userId)->check(
				ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
				DashboardAccessItem::createFromEntity($dashboard),
			);
		// The launch is not behind BIConstructorAccess, so the tool switch it checks is asked here.
		$this->isToolEnabled = $isToolEnabled ?? static fn(): bool =>
			!Loader::includeModule('intranet')
			|| ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		;
		// The same market subscription and tariff checks the detail page makes before it shows the report.
		$this->isAvailableByPlan = $isAvailableByPlan ?? static function(Model\Dashboard $dashboard): bool {
			if (!MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType()))
			{
				return false;
			}

			$appId = (string)$dashboard->getAppId();

			return $appId === '' || DashboardTariffConfigurator::isAvailableDashboard($appId);
		};
	}

	public function check(Model\Dashboard $dashboard, int $userId): ChartAnalysisAvailability
	{
		$portalAvailability = $this->checkPortal($userId);
		if (
			$portalAvailability->state === ChartAnalysisAvailability::HIDDEN
			|| !$this->isDashboardAvailable($dashboard, $userId)
		)
		{
			return new ChartAnalysisAvailability(ChartAnalysisAvailability::HIDDEN);
		}

		return $portalAvailability;
	}

	/**
	 * The portal half of the answer does not depend on the report: one instance of the service answers it
	 * once per user and keeps the report checks per call.
	 */
	private function checkPortal(int $userId): ChartAnalysisAvailability
	{
		if ($this->portalAvailability !== null && $this->portalUserId === $userId)
		{
			return $this->portalAvailability;
		}

		$this->portalUserId = $userId;
		$this->portalAvailability = $this->resolvePortalAvailability($userId);

		return $this->portalAvailability;
	}

	private function resolvePortalAvailability(int $userId): ChartAnalysisAvailability
	{
		if (
			$userId <= 0
			|| !($this->isFlagEnabled)()
			|| !($this->isCloudPortal)()
			|| !($this->isBuilderEnabled)()
			|| !($this->isToolEnabled)()
			|| !($this->isBiReady)()
			|| !($this->isBitrixGptAvailable)()
			|| !($this->isAgreementAccepted)()
		)
		{
			return new ChartAnalysisAvailability(ChartAnalysisAvailability::HIDDEN);
		}

		if ($this->subscriptionService->isRequired())
		{
			return new ChartAnalysisAvailability(
				ChartAnalysisAvailability::SUBSCRIPTION_REQUIRED,
				DigestSetupSubscriptionService::PROMOTER_CODE,
			);
		}

		return new ChartAnalysisAvailability(ChartAnalysisAvailability::READY);
	}

	private function isDashboardAvailable(Model\Dashboard $dashboard, int $userId): bool
	{
		return in_array(
			$dashboard->getStatus(),
			[
				SupersetDashboardTable::DASHBOARD_STATUS_READY,
				SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
			],
			true,
		)
			&& ($dashboard->getExternalId() ?? 0) > 0
			&& ($this->canView)($userId, $dashboard)
			&& ($this->isAvailableByPlan)($dashboard)
		;
	}
}
