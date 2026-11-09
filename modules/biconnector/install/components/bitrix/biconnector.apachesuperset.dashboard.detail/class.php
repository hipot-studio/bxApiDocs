<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\Update\DashboardGroupRights\Converter;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\BitrixGptChat;
use Bitrix\BIConnector\Integration\Superset\Integrator\ServiceLocation;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardUrlParameterTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\ChartAnalysis\ChartAnalysisAvailabilityService;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\BitrixGptBiAnnouncement;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailability;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailabilityService;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseLock;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Public\Dto\ChartAnalysisAvailability;
use Bitrix\BIConnector\Public\Provider\ChartAnalysisAvailabilityProvider;
use Bitrix\BIConnector\Public\Provider\DashboardDetailInfoProvider;
use Bitrix\BIConnector\Public\Services\AhaMoment\AhaMomentSpotlightOptions;
use Bitrix\BIConnector\Public\Services\AhaMoment\AhaMomentSpotlightResolver;
use Bitrix\BIConnector\Public\Command\DashboardView;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\BIConnector\Superset;
use Bitrix\Main\Application;

Loader::includeModule("biconnector");

class ApacheSupersetDashboardDetailComponent extends CBitrixComponent
{
	private SupersetController $supersetController;

	private ?Dashboard $dashboard;
	private int $dashboardId;
	private bool $isMobile = false;

	public function onPrepareComponentParams($arParams)
	{
		$this->dashboardId = (int)($arParams['DASHBOARD_ID'] ?? 0);
		$this->isMobile = (bool)($arParams['IS_MOBILE'] ?? false);
		$arParams['CODE'] = $arParams['CODE'] ?? '';
		$arParams['URL_PARAMS'] = $this->getUrlParams();

		return parent::onPrepareComponentParams($arParams);
	}

	/**
	 * On mobile every exit renders the single `mobile` template, which turns arResult into a
	 * bridge signal for the native shell; on web the original page name is used unchanged.
	 */
	private function renderTemplate(string $page = ''): void
	{
		$this->includeComponentTemplate($this->isMobile ? 'mobile' : $page);
	}

	private function getSupersetController(): SupersetController
	{
		if (!isset($this->supersetController))
		{
			$this->supersetController = new SupersetController();
		}

		return $this->supersetController;
	}

	private function prepareResult(): void
	{
		$this->arResult = [
			'FEATURE_AVAILABLE' => true,
			'DASHBOARD_UUID' => null,
			'GUEST_TOKEN' => null,
			'SUPERSET_DOMAIN' => '',
			'ERROR_MESSAGES' => [],
			'NATIVE_FILTERS' => '',
			'FILTERS' => [],
			'INFO_AHA_MOMENT' => [
				'canShow' => false,
				'id' => null,
				'showDelaySeconds' => 0,
				'title' => Loc::getMessage('SUPERSET_DASHBOARD_DETAIL_INFO_AHA_MOMENT_TITLE'),
				'description' => Loc::getMessage('SUPERSET_DASHBOARD_DETAIL_INFO_AHA_MOMENT_DESCRIPTION'),
			],
			'DASHBOARD_ONBOARDING' => (new Superset\UI\DashboardOnboarding\DashboardOnboardingConfig(
				Superset\UI\DashboardOnboarding\DashboardOnboardingMode::None
			))->toArray(),
			'MARKET_COLLECTION_URL' => MarketDashboardManager::getMarketCollectionUrl(),
			'SUPERSET_SERVICE_LOCATION' => ServiceLocation::getCurrentDatacenterLocationRegion(),
		];

		$embeddedDebugMode = Option::get('biconnector', 'embedded_debug_mode', 'N');
		$this->arResult['EMBEDDED_DEBUG_MODE'] = $embeddedDebugMode === 'Y';
	}

	private function isComponentAvailable(): bool
	{
		if (in_array(SupersetInitializer::getSupersetStatus(), [
			SupersetInitializer::SUPERSET_STATUS_DELETED,
			SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE,
			SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED,
		], true))
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

		if (!Superset\DomainLinkService::getInstance()->isLinked())
		{
			return false;
		}

		// A report whose term is over is opened only from the grid, where it carries a padlock: a direct link
		// must not be a way around it, so it leads back to the grid.
		if (SelfHostedLicenseLock::isDashboardLocked())
		{
			return false;
		}

		if (SupersetInitializer::isRebindRequired())
		{
			return false;
		}

		return true;
	}

	public function executeComponent()
	{
		if (!$this->isComponentAvailable())
		{
			if ($this->isMobile)
			{
				$this->arResult['MOBILE_REASON'] = 'superset_unavailable';
				$this->renderTemplate();

				return;
			}

			LocalRedirect('/bi/dashboard/');
		}

		if (SupersetInitializer::isSupersetUpdating())
		{
			$this->leaveForSupersetUpdate();

			return;
		}

		$superset = new SupersetController();
		$superset->initializeOrCheckSupersetStatus();

		if (SupersetInitializer::isSupersetUpdating())
		{
			$this->leaveForSupersetUpdate();

			return;
		}

		if (SupersetInitializer::isSupersetLoading())
		{
			$dashboard = SupersetDashboardTable::getByPrimary($this->dashboardId)->fetch();
			if (!$dashboard)
			{
				$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND');
				$this->arResult['MOBILE_REASON'] = 'no_access';
				$this->renderTemplate();

				return;
			}

			$dashboard['STATUS'] = SupersetDashboardTable::DASHBOARD_STATUS_LOAD;
			$this->arResult['PERIODIC_RELOAD'] = true; // Waiting for Superset to unfreeze
			$this->showStartupTemplate($dashboard);

			return;
		}

		$initResult = $this->initDashboard();
		if (!$initResult)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND');
			$this->arResult['MOBILE_REASON'] = 'no_access';
			$this->renderTemplate();

			return;
		}

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $this->dashboardId,
			'TYPE' => $this->dashboard->getType(),
			'STATUS' => $this->dashboard->getStatus(),
		]);

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			$errorCode =
				$this->dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
					? 'BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND'
					: 'BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_ACCESS_ERROR'
			;

			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage($errorCode);
			$this->arResult['MOBILE_REASON'] = 'no_access';
			$this->renderTemplate();

			return;
		}

		if (!MarketAccessManager::getInstance()->isDashboardAvailableByType($this->dashboard->getType()))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BI_CONNECTOR_SUPERSET_MARKET_DASHBOARD_IS_NOT_AVAILABLE_BY_SUB');
			$this->arResult['MOBILE_REASON'] = 'no_access';
			$this->renderTemplate();

			return;
		}

		if (
			!empty($this->dashboard->getAppId())
			&& !DashboardTariffConfigurator::isAvailableDashboard($this->dashboard->getAppId())
		)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BI_CONNECTOR_SUPERSET_DASHBOARD_IS_NOT_TARIFF_AVAILABLE');
			$this->arResult['MOBILE_REASON'] = 'no_access';
			$this->renderTemplate();

			return;
		}

		if ($this->dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
		{
			$isSupersetReady = SupersetInitializer::isSupersetReady();
			$isSelfHosted = Superset\Selfhost\SupersetHostMode::isSelfHosted();
			if (!$isSupersetReady)
			{
				SupersetInitializer::saveInitData($this->dashboardId);
				if ($isSelfHosted)
				{
					SupersetInitializer::startupSuperset();
					$isSupersetReady = SupersetInitializer::isSupersetReady();
				}
			}

			$this->showStartupTemplate($this->dashboard->toArray(), firstStartup: !$isSupersetReady);
			if (!$isSupersetReady && !$isSelfHosted)
			{
				Application::getInstance()->addBackgroundJob(function() {
					SupersetInitializer::startupSuperset();
				});
			}

			return;
		}

		$this->prepareResult();

		if (SupersetInitializer::isSupersetUpdating())
		{
			$this->leaveForSupersetUpdate();

			return;
		}

		// Status can become LOAD during initDashboard if instance was freezed. Then fetching embedded token will
		// be blocked by ReadyGate and this will render "dashboard not found".
		if (SupersetInitializer::isSupersetLoading())
		{
			$this->arResult['PERIODIC_RELOAD'] = true; // Waiting for Superset to unfreeze
			$this->showStartupTemplate($this->dashboard->toArray());

			return;
		}

		if (SupersetInitializer::isSupersetUnavailable())
		{
			$this->showStartupTemplate($this->dashboard->toArray());

			return;
		}

		$skeletonStatuses = [SupersetDashboardTable::DASHBOARD_STATUS_LOAD, SupersetDashboardTable::DASHBOARD_STATUS_FAILED];
		if (in_array($this->dashboard->getStatus(), $skeletonStatuses, true))
		{
			$dashboard['STATUS'] = $this->dashboard->getStatus();
			$this->showStartupTemplate($this->dashboard->toArray());

			return;
		}

		$this->dashboard->loadCredentials();
		if (SupersetInitializer::isSupersetUpdating())
		{
			$this->leaveForSupersetUpdate();

			return;
		}

		if (
			(
				$this->dashboard->isAvailableDashboard()
				&& !$this->dashboard->isSupersetDashboardDataLoaded()
			)
			|| !$this->dashboard->isAvailableDashboard()
			|| !$this->dashboard->getEmbeddedCredentials()
		)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND');
			$this->arResult['MOBILE_REASON'] = 'superset_unavailable';
			$this->renderTemplate();

			return;
		}

		$this->prepareAccessParams();
		$this->prepareDigestSetupSurface();
		$this->prepareBitrixGptChat();
		$this->prepareChartAnalysisSurface();

		$this->prepareEmbeddedCredentials();
		$this->prepareNativeFilters();

		$this->prepareUrlParams();
		$this->prepareInfoAhaMoment();
		$this->prepareDashboardOnboarding();
		$this->prepareBitrixGptAnnouncement();
		$this->prepareMcpBiConnection();

		$this->arResult['IS_USE_EXTERNAL_DATASETS'] = $this->dashboard->isUseExternalDatasets();
		$this->arResult['CAN_SEND_STARTUP_METRIC'] = self::canSendStartupSupersetMetric();

		MarketDashboardManager::getInstance()->logDashboardActivity($this->dashboard);

		if (!Feature::isCheckPermissionsByGroup())
		{
			Converter::updateToGroup(true);
		}

		(new BIConnector\Access\Superset\Synchronizer(CurrentUser::get()->getId()))->sync();

		(new DashboardView\AddSupersetDashboardViewCommand(
			$this->dashboardId,
			CurrentUser::get()->getId()
		))->run();

		// The requests made above may be the first to receive the update signal.
		if (SupersetInitializer::isSupersetUpdating())
		{
			$this->leaveForSupersetUpdate();

			return;
		}

		$this->renderTemplate();

		Application::getInstance()
			->addBackgroundJob(fn() => Superset\Updater\ClientUpdater::update())
		;

		Application::getInstance()
			->addBackgroundJob(fn() => MarketDashboardManager::getInstance()->updateApplications())
		;

		// Backfill physical-dataset descriptions on a live instance (a report is being viewed), never
		// from the installer/updater where Superset may be unreachable. Throttled to at most once an
		// hour and one-shot (stops once it succeeds).
		Application::getInstance()
			->addBackgroundJob(fn() => BIConnector\Internal\Services\Dataset\DatasetLabelBackfill::run())
		;
	}

	/**
	 * The whole section is blocked while the instance is updating, and the stub is shown on the grid page.
	 * The mobile shell has no such page: it is told the report is not ready yet and keeps polling the state.
	 */
	private function leaveForSupersetUpdate(): void
	{
		if ($this->isMobile)
		{
			$this->arResult['MOBILE_STATE'] = 'preparing';
			$this->renderTemplate();

			return;
		}

		LocalRedirect('/bi/dashboard/');
	}

	private function showStartupTemplate(array $dashboard, bool $firstStartup = false): void
	{
		$this->arResult['DASHBOARD_TITLE'] = $dashboard['TITLE'];
		$this->arResult['DASHBOARD_ID'] = $dashboard['ID'];
		$this->arResult['DASHBOARD_STATUS'] = $dashboard['STATUS'];
		$this->arResult['DASHBOARD_TYPE'] = $dashboard['TYPE'];

		$this->arResult['IS_FIRST_STARTUP'] = $firstStartup;
		$this->arResult['SUPERSET_STATUS'] = SupersetInitializer::getSupersetStatus();
		$this->arResult['SUPERSET_SERVICE_LOCATION'] = ServiceLocation::getCurrentDatacenterLocationRegion();
		$this->arResult['MOBILE_STATE'] = 'preparing';

		$this->renderTemplate('startup');
	}

	private function initDashboard(): bool
	{
		$superset = $this->getSupersetController();
		$this->dashboard = $superset->getDashboardRepository()->getById($this->dashboardId, true);

		return !empty($this->dashboard);
	}

	private function prepareEmbeddedCredentials(): void
	{
		$this->arResult['DASHBOARD_TYPE'] = $this->dashboard->getType();
		$this->arResult['DASHBOARD_TITLE'] = $this->dashboard->getTitle();
		$this->arResult['DASHBOARD_UUID'] = $this->dashboard->getEmbeddedCredentials()->uuid;
		$this->arResult['DASHBOARD_ID'] = $this->dashboard->getId();
		$this->arResult['GUEST_TOKEN'] = $this->dashboard->getEmbeddedCredentials()->guestToken;
		$this->arResult['SUPERSET_DOMAIN'] = $this->dashboard->getEmbeddedCredentials()->supersetDomain;
		$this->arResult['DASHBOARD_EDIT_URL'] = $this->dashboard->getEditUrl();
		$this->arResult['DASHBOARD_APP_ID'] = $this->dashboard->getAppId();
	}

	private function prepareNativeFilters(): void
	{
		$nativeFilter = new EmbeddedFilter\NativeFilterBuilder($this->dashboard);

		if ($this->arParams['URL_PARAMS'] && is_array($this->arParams['URL_PARAMS']))
		{
			$nativeFilter->setValuesFromUrlParameters($this->arParams['URL_PARAMS']);
		}

		$this->arResult['NATIVE_FILTERS'] = $nativeFilter->getFormattedFilter();
		$this->arResult['FILTERS'] = $nativeFilter->getPresetFilterOptions();
	}

	private function prepareUrlParams(): void
	{
		$this->arResult['URL_PARAMS'] = [];

		$uriVariables = SupersetDashboardUrlParameterTable::getList([
			'filter' => ['=DASHBOARD_ID' => $this->dashboard->getId()],
			'select' => ['ID', 'CODE'],
		])
			->fetchCollection()
		;

		$existedCodes = [];
		foreach ($uriVariables as $uriVariable)
		{
			$existedCodes[$uriVariable->getCode()] = $uriVariable->getId();
		}

		foreach ($existedCodes as $code => $id)
		{
			// A global parameter - `current_user` - is a value of the server and not of the address: it reaches
			// the page only through a link Service::getEmbeddedUrl() built. A saved link, a page opened by id and
			// the mobile frame page carry no parameters at all, and the dashboard would answer with the rows of
			// everyone. loadGlobalValue() gives nothing for a parameter of a scope: that one has no value outside
			// the place the report was opened from.
			$parameter = UrlParameter\Parameter::tryFrom($code);
			$value = $this->arParams['URL_PARAMS'][$code]
				?? ($parameter ? UrlParameter\ScopeMap::loadGlobalValue($parameter) : null);

			if ($value !== null)
			{
				$this->arResult['URL_PARAMS'][$code] = $value;
			}
		}

		// The company structure filter may be added in Superset after the parameters were saved;
		// a dashboard whose filters were not loaded may hold it as well.
		$requiredParamList = UrlParameter\RequiredParameters::getList($this->dashboard)
			?? UrlParameter\ScopeMap::getRequiredParamList()
		;
		foreach (array_keys($requiredParamList) as $code)
		{
			$this->arResult['URL_PARAMS'][$code] ??= UrlParameter\ScopeMap::loadGlobalValue(
				UrlParameter\Parameter::from($code),
			);
		}
	}

	private function prepareInfoAhaMoment(): void
	{
		$dashboardInfo = $this->getDashboardDetailInfoProvider()->getByDashboard($this->dashboard);
		if (
			$dashboardInfo === null
			|| (
				trim((string)$dashboardInfo->getDescription()) === ''
				&& empty($dashboardInfo->getImages())
			)
		)
		{
			return;
		}

		$spotlightConfig = $this->getAhaMomentSpotlightResolver()->resolve(
			new AhaMomentSpotlightOptions(
				baseId: 'biconnector-apachesuperset-dashboard-detail-info',
				maxShows: 2,
				showDelaySeconds: 10,
			),
		);

		$this->arResult['INFO_AHA_MOMENT']['canShow'] = $spotlightConfig->canShow();
		$this->arResult['INFO_AHA_MOMENT']['id'] = $spotlightConfig->getSpotlightId();
		$this->arResult['INFO_AHA_MOMENT']['showDelaySeconds'] = $spotlightConfig->getShowDelaySeconds();
	}

	private function prepareDashboardOnboarding(): void
	{
		$context = new Superset\UI\DashboardOnboarding\DashboardOnboardingContext(
			userId: (int)CurrentUser::get()->getId(),
			dashboardType: $this->dashboard->getType(),
			dashboardStatus: $this->dashboard->getStatus(),
			dashboardCreatedById: (int)$this->dashboard->getField('CREATED_BY_ID'),
			canEdit: $this->arResult['CAN_EDIT'] === 'Y',
			isBitrixGptAvailable: $this->arResult['BITRIX_GPT_CHAT']['IS_AVAILABLE'],
		);

		$config = $this->getDashboardOnboardingService()->getConfig($context);
		$this->arResult['DASHBOARD_ONBOARDING'] = $config->toArray();

		// Both the tour and the info hint point at the same header button: the hint waits for
		// another visit instead of overlapping the onboarding.
		if ($config->getMode() !== Superset\UI\DashboardOnboarding\DashboardOnboardingMode::None)
		{
			$this->arResult['INFO_AHA_MOMENT']['canShow'] = false;
		}
	}

	/**
	 * Called from the ready render branch only: startup and error screens must not carry the announcement.
	 */
	private function prepareBitrixGptAnnouncement(): void
	{
		$this->arResult['BITRIXGPT_BI_ANNOUNCEMENT'] = [
			'canShow' => BitrixGptBiAnnouncement::canShowForCurrentUser(),
			'surface' => 'detail',
		];
	}

	/**
	 * Called from the ready render branch only, and only while the feature is on: with the option off
	 * neither the key nor the header action exists. The catalogue of the operations travels in the
	 * settings of the popup extension, which the header asks for at the press of the action, so the page
	 * of the report neither builds the catalogue nor carries it.
	 */
	private function prepareMcpBiConnection(): void
	{
		if (!Feature::isMcpBiConnectionEnabled())
		{
			return;
		}

		$this->arResult['MCP_BI_CONNECTION'] = true;
	}

	private function prepareAccessParams(): void
	{
		$accessItem = DashboardAccessItem::createFromEntity($this->dashboard);
		$accessController = AccessController::getCurrent();

		$canExport = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $accessItem);
		$this->arResult['CAN_EXPORT'] = $canExport ? 'Y' : 'N';

		$canEdit = false;
		if (
			$this->dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
			|| $this->dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET
		)
		{
			$canEdit = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $accessItem);
		}
		else if ($this->dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$canEdit = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem);
		}
		$this->arResult['CAN_EDIT'] = $canEdit ? 'Y' : 'N';

		// Drafts can't be shared; FAILED can't be shared either — the share page has no retry for it.
		$nonSharableStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
			SupersetDashboardTable::DASHBOARD_STATUS_FAILED,
		];
		$canShare =
			$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE)
			&& !in_array($this->dashboard->getStatus(), $nonSharableStatuses, true)
		;
		$this->arResult['CAN_SHARE'] = $canShare ? 'Y' : 'N';

		$shareProvider = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.provider.share');
		$this->arResult['SHARE_DATA'] = $shareProvider->getAuthorShareState(
			$this->dashboard->getId(),
			(int)CurrentUser::get()->getId(),
		);
		$this->arResult['IS_SHARE_BANNED'] = $shareProvider->isShareBanned($this->dashboard->getId()) ? 'Y' : 'N';
	}

	protected function prepareDigestSetupSurface(): void
	{
		$availability = $this->createDigestSetupAvailabilityService()->check(
			$this->dashboard,
			(int)CurrentUser::get()->getId(),
		);
		if ($availability->state === DigestSetupAvailability::HIDDEN)
		{
			return;
		}

		$this->arResult['DIGEST_SETUP'] = [
			'dashboardId' => $this->dashboard->getId(),
			'availability' => $availability->toArray(),
		];
	}

	protected function createDigestSetupAvailabilityService(): DigestSetupAvailabilityService
	{
		return ServiceLocator::getInstance()->get(DigestSetupAvailabilityService::class);
	}

	/**
	 * The header button, the chart analysis surface and its availability check share one answer per hit.
	 */
	protected function prepareBitrixGptChat(): void
	{
		$isAvailable = BitrixGptChat::isAvailable();

		$this->arResult['BITRIX_GPT_CHAT'] = [
			'IS_AVAILABLE' => $isAvailable,
			'NAME' => $isAvailable ? BitrixGptChat::getName() : '',
		];
	}

	protected function prepareChartAnalysisSurface(): void
	{
		$availability = $this->createChartAnalysisAvailabilityProvider()->check(
			$this->dashboard,
			(int)CurrentUser::get()->getId(),
		);
		if ($availability->state === ChartAnalysisAvailability::HIDDEN)
		{
			return;
		}

		$this->arResult['CHART_ANALYSIS'] = [
			'dashboardId' => $this->dashboard->getId(),
			'state' => $availability->state,
			'promoterCode' => $availability->promoterCode,
			'bitrixGptName' => $this->arResult['BITRIX_GPT_CHAT']['NAME'],
		];
	}

	protected function createChartAnalysisAvailabilityProvider(): ChartAnalysisAvailabilityProvider
	{
		return new ChartAnalysisAvailabilityProvider(
			new ChartAnalysisAvailabilityService(
				isBitrixGptAvailable: fn(): bool => $this->arResult['BITRIX_GPT_CHAT']['IS_AVAILABLE'],
			),
		);
	}

	private function getUrlParams(): ?array
	{
		return
			$this->request->get('params') && is_string($this->request->get('params'))
				? Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Service::decode($this->request->get('params'))
				: null;
	}

	private static function canSendStartupSupersetMetric(): bool
	{
		$supersetStatus = SupersetInitializer::getSupersetStatus();
		$metricAlreadySend = Option::get('biconnector', 'superset_startup_metric_send', false);

		return (
			$supersetStatus === SupersetInitializer::SUPERSET_STATUS_READY
			&& !$metricAlreadySend
		);
	}

	private function getDashboardDetailInfoProvider(): DashboardDetailInfoProvider
	{
		return ServiceLocator::getInstance()->get('biconnector.provider.dashboardDetailInfo');
	}

	private function getAhaMomentSpotlightResolver(): AhaMomentSpotlightResolver
	{
		return ServiceLocator::getInstance()->get('biconnector.service.ahaMomentSpotlightResolver');
	}

	private function getDashboardOnboardingService(): Superset\UI\DashboardOnboarding\DashboardOnboardingService
	{
		return ServiceLocator::getInstance()->get(Superset\UI\DashboardOnboarding\DashboardOnboardingService::class);
	}
}
