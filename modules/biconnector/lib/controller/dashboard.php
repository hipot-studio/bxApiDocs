<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\Service\DashboardGroupService;
use Bitrix\BIConnector\Public\Command\Dashboard\InstallDashboardCommand;
use Bitrix\BIConnector\Public\Command\DashboardChat\DashboardDiscussionChatResult;
use Bitrix\BIConnector\Public\Command\DashboardChat\GetOrCreateDashboardDiscussionChatCommand;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetTagTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Internal\Digest\Onboarding\DigestSetupOnboarding;
use Bitrix\BIConnector\Internal\Digest\Service\DigestRecipientAccessCheckService;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupPreparationService;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\BitrixGptBiAnnouncement;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\Dashboard\ExternalFilter\RlsRuleBuilder;
use Bitrix\BIConnector\Superset\Demo\DemoService;
use Bitrix\BIConnector\Public\Command\Share\CreateShareCommand;
use Bitrix\BIConnector\Public\Command\Share\CreateShareCommandHandler;
use Bitrix\BIConnector\Public\Command\Share\DeactivateShareCommand;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Dashboard\ScreenshotExporter;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard\DemoAction;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Error\IntegrationErrorPresenter;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;

class Dashboard extends Controller
{
	private const TOP_MENU_DASHBOARDS_OPTION_NAME = 'top_menu_dashboards';
	private const PINNED_DASHBOARDS_OPTION_NAME = 'grid_pinned_dashboards';
	private const DEMO_ERROR_UNAVAILABLE = 'DEMO_UNAVAILABLE';
	private const DEMO_ERROR_INVALID_REPORT_CODE = 'DEMO_INVALID_REPORT_CODE';
	private const DEMO_REPORT_CODE_MAX_LENGTH = 128;
	private const BITRIXGPT_BI_ANNOUNCE_LOCK_PREFIX = 'biconnector.bitrixgpt-bi-announce.';
	private const CONSTRUCTOR_WORKSPACE_PATH = '/dashboard/list/';

	/**
	 * @return array
	 */
	protected function getDefaultPreFilters(): array
	{
		$additionalFilters = [
			new BIConstructorAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			...$additionalFilters,
		];
	}

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['getDashboardEmbeddedData'] = [
			'-prefilters' => [
				BIConstructorAccess::class
			],
		];
		$configureActions['getDemoEmbeddedCredentials'] = [
			'-prefilters' => [
				BIConstructorAccess::class,
			],
		];
		// Both digest setup actions change state: the first one copies and starts a template, the second
		// one spends the onboarding mark. The default HttpMethod filter of the engine allows GET as well,
		// which would carry sessid in the query string, so a stricter filter is appended to them.
		$configureActions['prepareDigestSetup'] = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];
		$configureActions['claimDigestSetupOnboarding'] = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];

		return $configureActions;
	}

	/**
	 * @param array|null $screenState Native filter id => its state on the report screen, as the browser read it.
	 *     Taken as an intention only: it is read after every check has passed and never decides the setup.
	 */
	public function prepareDigestSetupAction(
		DigestSetupPreparationService $preparationService,
		?int $dashboardId = null,
		?array $screenState = null,
	): array
	{
		$prepared = $preparationService->prepare($this->getDigestSetupUserId(), $dashboardId, $screenState);
		$this->addErrors($prepared->getErrors());

		return $prepared->getData();
	}

	public function claimDigestSetupOnboardingAction(DigestSetupOnboarding $onboarding, string $version): array
	{
		return [
			'claimed' => $onboarding->claim($version, $this->getDigestSetupUserId()),
		];
	}

	protected function getDigestSetupUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	public function getPrimaryAutoWiredParameter(): ExactParameter
	{
		return new ExactParameter(
			Model\Dashboard::class,
			'dashboard',
			function($className, $id)
			{
				$dashboardId = (int)$id;
				if ($dashboardId <= 0)
				{
					$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

					return null;
				}

				$superset = new SupersetController();
				$dashboard = $superset->getDashboardRepository()->getById($dashboardId);
				if (!$dashboard)
				{
					$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

					return null;
				}

				return $dashboard;
			}
		);
	}

	public function copyAction(Model\Dashboard $dashboard): ?array
	{
		// A dashboard the user cannot open is answered as a missing one, so copying tells nothing about it.
		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

			return null;
		}

		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_COPY')));

			return null;
		}

		$superset = new SupersetController();
		$integrator = $superset->getIntegrator();
		$externalId = (int)$dashboard->getExternalId();

		if ($externalId <= 0)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

			return null;
		}
		$newTitle = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_COPIED_DASHBOARD_TITLE', [
			'#DASHBOARD_TITLE#' => $dashboard->getTitle(),
		])  ?? '';
		$response = $integrator->copyDashboard($externalId, $newTitle);
		/** @var array $data */
		$data = $response->getData();
		if (!$data)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_COPY_ERROR')));

			return null;
		}

		$copiedDashboardExternalId = (int)($data['id'] ?? 0);
		$title = $data['title'] ?? '';

		$addData = [
			'EXTERNAL_ID' => $copiedDashboardExternalId,
			'TITLE' => $title,
			'SOURCE_ID' => $dashboard->getId(),
			'APP_ID' => $dashboard->getAppId(),
			'TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
			'CREATED_BY_ID' => $this->getCurrentUser()?->getId(),
		];

		$addResult = SupersetDashboardTable::add($addData);
		if (!$addResult->isSuccess())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_COPY_ERROR')));

			return null;
		}

		$copiedDashboardId = $addResult->getId();

		$eventData = [
			'dashboardId' => (int)$copiedDashboardId,
			'sourceDashboardId' => $dashboard->getId(),
			'createdById' => $addData['CREATED_BY_ID'],
		];
		$afterCopyEvent = new Event('biconnector', 'onAfterCopyDashboard', $eventData);
		$afterCopyEvent->send();

		$newDashboard = $superset->getDashboardRepository()->getById($copiedDashboardId, true);
		if ($newDashboard)
		{
			foreach ($dashboard->getOrmObject()->fillTags() as $tag)
			{
				SupersetDashboardTagTable::add([
					'TAG_ID' => $tag->getId(),
					'DASHBOARD_ID' => $newDashboard->getId(),
				]);

				$newDashboard->getOrmObject()->unsetTags();
			}
			$scopes = ScopeService::getInstance()->getDashboardScopes($dashboard->getId());
			ScopeService::getInstance()->saveDashboardScopes($copiedDashboardId, $scopes);

			$newDashboard->getOrmObject()->unsetScope();

			if (!$dashboard->getOrmObject()->isUrlParamsFilled())
			{
				$dashboard->getOrmObject()->fillUrlParams();
			}

			$paramCodes = $dashboard->getOrmObject()->getUrlParams()->getCodeList();
			(new UrlParameter\Service($newDashboard->getOrmObject()))
				->saveDashboardParams($paramCodes, $scopes)
			;

			$newDashboard->getOrmObject()->unsetUrlParams();

			if (!$dashboard->getOrmObject()->isGroupsFilled())
			{
				$dashboard->getOrmObject()->fillGroups();
			}

			$groupIds = $dashboard->getOrmObject()->getGroups()?->getIdList() ?? [];
			DashboardGroupService::saveDashboardGroupBindings($newDashboard->getId(), $groupIds);
			$newDashboard->getOrmObject()->unsetGroups();
			DashboardManager::notifyDashboardListChanged();

			$gridRow = DashboardGrid::prepareDashboardRowData($newDashboard, ['IS_ACCESS_ALLOWED' => true]);
			$data['id'] = $copiedDashboardId;
			$data['detail_url'] = "/bi/dashboard/detail/{$copiedDashboardId}/";
			$data['columns'] = $gridRow['columns'];
			$data['actions'] = $gridRow['actions'];

			return ['dashboard' => $data];
		}

		return null;
	}

	public function exportAction(Model\Dashboard $dashboard, int $groupId): ?array
	{
		if (!MarketDashboardManager::getInstance()->isExportEnabled())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EXPORT')));

			return null;
		}

		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EXPORT')));

			return null;
		}

		$integrator = IntegratorFactory::getInstance();
		$externalDashboardId = (int)$dashboard->getExternalId();
		if ($externalDashboardId <= 0)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

			return null;
		}

		// older portals read this block: PERIOD_NONE keeps them from applying their own period
		$dashboardSettings = [
			'period' => [
				'FILTER_PERIOD' => EmbeddedFilter\DateTime::PERIOD_NONE,
				'DATE_FILTER_START' => null,
				'DATE_FILTER_END' => null,
				'INCLUDE_LAST_FILTER_DATE' => null,
			],
		];
		$dashboardScopes = Model\SupersetScopeTable::getList([
			'filter' => [
				'=DASHBOARD_ID' => $dashboard->getId(),
				'!%=SCOPE_CODE' => ScopeService::BIC_SCOPE_AUTOMATED_SOLUTION_PREFIX . '%',
			],
		])
			->fetchCollection()
			->getScopeCodeList()
		;
		$dashboardSettings['scope'] = $dashboardScopes;
		if (!$dashboard->getOrmObject()->isUrlParamsFilled())
		{
			$dashboard->getOrmObject()->fillUrlParams();
		}

		$dashboardSettings['urlParameters'] = $dashboard->getOrmObject()->getUrlParams()->getCodeList();

		$groupCode = SupersetDashboardGroupTable::getById($groupId)
			->fetchObject()
			->getCode()
		;
		if ($groupCode && in_array($groupCode, ScopeService::getSystemGroupCode()))
		{
			$dashboardSettings['groupCode'] = $groupCode;
		}
		else
		{
			$dashboardSettings['groupCode'] = ScopeService::BIC_SCOPE_CRM;
		}

		$response = $integrator->exportDashboard($externalDashboardId, $dashboardSettings);
		if ($response->hasErrors())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_EXPORT_ERROR')));

			return null;
		}

		/** @var array $data */
		$data = $response->getData();
		if ($data)
		{
			$contentSize = $data['contentSize'] ?? 0;
			$filePath = $data['filePath'] ?? '';
			if ((int)$contentSize <= 0)
			{
				$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_EXPORT_ERROR')));

				return null;
			}

			return ['filePath' => $filePath];
		}

		return null;
	}

	public function deleteAction(Model\Dashboard $dashboard): ?bool
	{
		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_DELETE, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_DELETE')));

			return null;
		}

		if ($dashboard->hasCopies())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR_COPIED')));

			return null;
		}

		if (
			!SystemDashboardManager::canDeleteSystemDashboard()
			&& $dashboard->isSystemDashboard()
		)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR_SYSTEM')));

			return null;
		}

		if ($dashboard->isMarketDashboard())
		{
			$dashboardId = $dashboard->getId();
			$relatedItemsResult = MarketDashboardManager::getInstance()->getMarketDashboardReusedEntities([$dashboardId]);
			if (!$relatedItemsResult->isSuccess())
			{
				$this->addError(IntegrationErrorPresenter::present(
					$relatedItemsResult->getErrors(),
					Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RELATED_ITEMS_ERROR'),
				));

				return null;
			}

			$relatedItems = $relatedItemsResult->getData();
			if (!empty($relatedItems[$dashboardId]))
			{
				$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR_HAS_RELATED_ENTITIES')));

				return null;
			}
		}

		if (
			$dashboard->isMarketDashboard()
			|| (
				SystemDashboardManager::canDeleteSystemDashboard()
				&&  $dashboard->isSystemDashboard()
			)
		)
		{
			$appCode = $dashboard->getAppId();
			$marketManager = MarketDashboardManager::getInstance();
			$uninstallResult = $marketManager->handleUninstallMarketApp($appCode);
			if (!$uninstallResult->isSuccess())
			{
				$this->addErrors($uninstallResult->getErrors());

				return null;
			}
		}

		if ($dashboard->isCustomDashboard())
		{
			$externalDashboardId = $dashboard->getExternalId();
			$response = IntegratorFactory::getInstance()->deleteDashboard([$externalDashboardId], $dashboard->isMarketDashboard());
			if ($response->hasErrors())
			{
				if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
				{
					$dashboard->getOrmObject()->delete();

					return true;
				}
				$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR')));

				return null;
			}

			$dashboard->getOrmObject()->delete();
		}

		return true;
	}

	public function getMarketDashboardRelatedItemsAction(Model\Dashboard $dashboard): ?array
	{
		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		if (!$dashboard->isMarketDashboard())
		{
			return [];
		}

		$dashboardId = $dashboard->getId();
		$relatedItemsResult = MarketDashboardManager::getInstance()->getMarketDashboardReusedEntities([$dashboardId]);
		if (!$relatedItemsResult->isSuccess())
		{
			$this->addError(IntegrationErrorPresenter::present(
				$relatedItemsResult->getErrors(),
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RELATED_ITEMS_ERROR'),
			));

			return null;
		}

		$relatedItems = $relatedItemsResult->getData();
		if (empty($relatedItems[$dashboardId]))
		{
			return [];
		}

		return $relatedItems[$dashboardId];
	}

	public function openDiscussionChatAction(Model\Dashboard $dashboard, CurrentUser $user): ?array
	{
		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		$currentUserId = (int)$user->getId();
		if ($currentUserId <= 0)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DISCUSSION_CHAT_OPEN_ERROR')));

			return null;
		}

		$commandResult = (new GetOrCreateDashboardDiscussionChatCommand(
			dashboardId: $dashboard->getId(),
			dashboardTitle: $dashboard->getTitle(),
			currentUserId: $currentUserId,
			dashboardCreatedById: (int)($dashboard->getOrmObject()?->getCreatedById() ?? 0),
		))->run();

		if (!$commandResult->isSuccess())
		{
			$this->addErrors($commandResult->getErrors());

			return null;
		}

		if (!$commandResult instanceof DashboardDiscussionChatResult)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DISCUSSION_CHAT_OPEN_ERROR')));

			return null;
		}

		$chatId = (int)$commandResult->getChatId();
		if ($chatId <= 0)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DISCUSSION_CHAT_OPEN_ERROR')));

			return null;
		}

		$dialogId = trim($commandResult->getDialogId());
		if ($dialogId === '')
		{
			$dialogId = "chat{$chatId}";
		}

		return [
			'chatId' => $chatId,
			'dialogId' => $dialogId,
			'isCreated' => $commandResult->isCreated(),
		];
	}

	public function restartImportAction(Model\Dashboard $dashboard): ?array
	{
		$availableDashboardStatusesToImport = [
			SupersetDashboardTable::DASHBOARD_STATUS_FAILED,
			SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED,
		];

		if (
			!SupersetInitializer::isSupersetReady()
			|| !in_array($dashboard->getStatus(), $availableDashboardStatusesToImport, true)
		)
		{
			return null;
		}

		Application::getInstance()->addBackgroundJob(function () use ($dashboard) {
			MarketDashboardManager::getInstance()->reinstallDashboard($dashboard->getId());
		});

		return [
			'restartedDashboardIds' => [$dashboard->getId()],
		];
	}

	public function getDashboardEmbeddedDataAction(Model\Dashboard $dashboard, array $urlParams = []): ?array
	{
		$accessController = AccessController::getCurrent();
		if (!$accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		$canExport = $accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $dashboard);

		$canEdit = false;
		if (
			$dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
			|| $dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET
		)
		{
			$canEdit = $accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $dashboard);
		}
		else if ($dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$canEdit = $accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $dashboard);
		}
		$canShare =
			$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE)
			&& ($dashboard->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		;

		$shareProvider = ServiceLocator::getInstance()->get('biconnector.provider.share');
		$shareData = $shareProvider->getAuthorShareState($dashboard->getId(), (int)CurrentUser::get()->getId());
		$isShareBanned = $shareProvider->isShareBanned($dashboard->getId());

		$dashboard->loadCredentials();
		$dashboard->loadProxyData();

		$paramsService = new UrlParameter\Service($dashboard->getOrmObject());
		$dashboardParamCodes = array_map(static fn($param) => $param->code(), $paramsService->getUrlParameters());
		if ($paramsService->isExistScopeParams())
		{
			$incomingParams = array_keys($urlParams);
			foreach ($incomingParams as $incomingParamCode)
			{
				if (!in_array($incomingParamCode, $dashboardParamCodes, true))
				{
					unset($urlParams[$incomingParamCode]);
				}
			}
		}
		else
		{
			$urlParams = $paramsService->getGlobalValues();
		}

		// The company structure filter may be added in Superset after the parameters were saved;
		// a dashboard whose filters were not loaded may hold it as well. The value of the client is not taken for it.
		$requiredParamList = UrlParameter\RequiredParameters::getList($dashboard)
			?? UrlParameter\ScopeMap::getRequiredParamList()
		;
		foreach (array_keys($requiredParamList) as $code)
		{
			$urlParams[$code] = UrlParameter\ScopeMap::loadGlobalValue(UrlParameter\Parameter::from($code));
		}

		return [
			'dashboard' => [
				'type' =>  $dashboard->getType(),
				'title' => $dashboard->getTitle(),
				'uuid' => $dashboard->getEmbeddedCredentials()->uuid,
				'id' => $dashboard->getId(),
				'guestToken' => $dashboard->getEmbeddedCredentials()->guestToken,
				'supersetDomain' => $dashboard->getEmbeddedCredentials()->supersetDomain,
				'editUrl' => $dashboard->getEditUrl(),
				'dashboardUrl' => $dashboard->getOrmObject()->getDetailUrl(),
				'embeddedUrl' => $paramsService->getEmbeddedUrl($urlParams),
				'appId' => $dashboard->getAppId(),
				'nativeFilters' => $dashboard->getNativeFilter(),
				'canExport' => $canExport,
				'canEdit' => $canEdit,
				'canShare' => $canShare,
				'shareData' => $shareData,
				'isShareBanned' => $isShareBanned,
				'urlParams' => $urlParams,
				'isUseExternalDatasets' => $dashboard->isUseExternalDatasets(),
				'isAvailable' => MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType()),
			],
		];
	}

	public function getDemoEmbeddedCredentialsAction(string $reportCode): ?array
	{
		$demoService = new DemoService();
		if (!$demoService->isAvailable())
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DEMO_ERROR_UNAVAILABLE'),
				self::DEMO_ERROR_UNAVAILABLE
			));

			return null;
		}

		$reportCode = trim($reportCode);
		if (
			mb_strlen($reportCode) > self::DEMO_REPORT_CODE_MAX_LENGTH
			|| !DemoAction::isReportCodeRoutable($reportCode)
		)
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DEMO_ERROR_INVALID_REPORT_CODE'),
				self::DEMO_ERROR_INVALID_REPORT_CODE
			));

			return null;
		}

		$result = $demoService->getCredentials($reportCode);

		$credentials = null;
		if ($result->credentials !== null)
		{
			$credentials = [
				'uuid' => $result->credentials->uuid,
				'guestToken' => $result->credentials->guestToken,
				'supersetDomain' => $result->credentials->supersetDomain,
			];
		}

		return [
			'state' => $result->state->value,
			'credentials' => $credentials,
		];
	}

	/**
	 * @param Model\Dashboard $dashboard
	 *
	 * @return array[]|null
	 */
	public function getDashboardUrlParametersAction(Model\Dashboard $dashboard): ?array
	{
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $dashboard->getId(),
			'TYPE' => $dashboard->getType(),
			'STATUS' => $dashboard->getStatus(),
		]);

		// The action tells which url parameters the report declares, which is part of reading it: whoever
		// may open the report already sees these filters in it. Editing rights are not the bar for that.
		$accessController = AccessController::getCurrent();
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		$result = [];
		foreach ($dashboard->getOrmObject()->fillUrlParams() as $parameter)
		{
			$result[] = [
				'code' => $parameter->getCode(),
				'defaultValue' => '',
			];
		}

		return [
			'urlParameters' => $result,
		];
	}

	/**
	 * Who of the chosen recipients will not be sent a digest of this report, and why.
	 *
	 * @see DigestRecipientAccessCheckService::check() for the answer and its limits.
	 *
	 * @param int[] $recipients
	 * @param array<string, int> $scopeValues Scope parameter code => chosen value id.
	 * @param list<array{id: int, withSubdepartments: bool}> $departments
	 */
	public function getDigestRecipientAccessIssuesAction(
		Model\Dashboard $dashboard,
		DigestRecipientAccessCheckService $accessCheckService,
		array $recipients = [],
		array $scopeValues = [],
		array $departments = [],
	): ?array
	{
		$checked = $accessCheckService->check(
			$dashboard,
			$this->getDigestSetupUserId(),
			$recipients,
			$scopeValues,
			$departments,
		);
		if (!$checked->isSuccess())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		return $checked->getData();
	}

	/**
	 * @param Model\Dashboard $dashboard
	 *
	 * @return bool|null
	 */
	public function installDashboardAction(Model\Dashboard $dashboard): ?bool
	{
		$result = (new InstallDashboardCommand($dashboard->getId()))->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	public function setDashboardTagsAction(Model\Dashboard $dashboard, array $tags = []): ?bool
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_TAG_MODIFY))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_TAG_MODIFY')));

			return null;
		}

		$userTags = SupersetTagTable::getList([
			'filter' => [
				'=ID' => $tags,
			],
			'select' => ['ID'],
		]);

		$tags = array_column($userTags->fetchAll(), 'ID');

		$ormParams = [
			'filter' => [
				'=DASHBOARD_ID' => $dashboard->getId(),
			],
		];
		$existed = [];
		$elements = SupersetDashboardTagTable::getList($ormParams);
		foreach ($elements->fetchCollection() as $element)
		{
			if (!in_array($element->getId(), $tags, true))
			{
				$element->delete();
			}
			else
			{
				$existed[] = $element->getId();
			}
		}

		if (count($tags) !== count($existed))
		{
			$newTags = array_diff($tags, $existed);

			foreach ($newTags as $tagId)
			{
				SupersetDashboardTagTable::add([
					'TAG_ID' => $tagId,
					'DASHBOARD_ID' => $dashboard->getId(),
				]);
			}
		}

		return true;
	}

	/**
	 * @example BX.ajax.runAction('biconnector.dashboard.getSupersetEntityLoginUrl', {data: {entityUrl: ''}});
	 *
	 * @param string $entityUrl
	 *
	 * @return string|null
	 */
	public function getSupersetEntityLoginUrlAction(string $entityUrl): ?string
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return $entityUrl;
		}

		return $this->getLoginUrlWithNext($entityUrl);
	}

	public function getConstructorUrlAction(): ?string
	{
		$canEdit = AccessController::getCurrent()->check(
			ActionDictionary::ACTION_BIC_DASHBOARD_EDIT,
		);

		if (!$canEdit)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_CONSTRUCTOR')));

			return null;
		}

		$loginUrl = (new SupersetController())->getLoginUrl();

		if (!$loginUrl)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_OPEN_CONSTRUCTOR_ERROR')));

			return null;
		}

		$url = new Uri($loginUrl);
		$url->addParams([
			'next' => self::CONSTRUCTOR_WORKSPACE_PATH,
		]);

		return $url->getLocator();
	}

	public function getEditUrlAction(Model\Dashboard $dashboard, string $editUrl): ?string
	{
		$canEdit = AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $dashboard);
		if (!$canEdit)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EDIT')));

			return null;
		}

		return $this->getLoginUrlWithNext($editUrl);
	}

	private function getLoginUrlWithNext(string $next): ?string
	{
		$loginUrlResult = (new SupersetController())->getLoginUrlResult();
		if (!$loginUrlResult->isSuccess())
		{
			$this->addError(IntegrationErrorPresenter::present(
				$loginUrlResult->getErrors(),
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_OPEN_CONSTRUCTOR_ERROR'),
			));

			return null;
		}

		$url = new Uri($loginUrlResult->getData()['url']);
		$url->addParams([
			'next' => $next,
		]);

		return $url->getLocator();
	}

	public function renameAction(Model\Dashboard $dashboard, string $title): void
	{
		$canEdit = AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $dashboard);
		if (!$canEdit)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EDIT')));

			return;
		}

		$supersetController = new SupersetController();
		if (!$supersetController->isSupersetEnabled() || SupersetInitializer::isSupersetUnavailable())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_UNAVAILABLE')));

			return;
		}

		$dashboard->loadProxyData();
		$editTitleResult = $this->editDashboardTitle($dashboard, $title);
		if (!$editTitleResult->isSuccess())
		{
			$this->addErrors($editTitleResult->getErrors());
		}
	}

	private function editDashboardTitle(Model\Dashboard $dashboard, string $newTitle): Result
	{
		$result = new Result();
		$newTitle = trim($newTitle);

		if (empty($dashboard->getEditUrl()))
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_NOT_FOUND') ?? '';

			return $result->addError(new UserFacingError($errorMsg));
		}

		if (empty($newTitle))
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_NOT_EMPTY') ?? '';

			return $result->addError(new UserFacingError($errorMsg));
		}

		if (!$dashboard->isAvailableDashboard())
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_NOT_READY') ?? '';

			return $result->addError(new UserFacingError($errorMsg));
		}

		if ($dashboard->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_ONLY_CUSTOM_MSGVER_1') ?? '';

			return $result->addError(new UserFacingError($errorMsg));
		}

		$changeResult = $dashboard->changeTitle($newTitle);
		if (!$changeResult->isSuccess())
		{
			$errorsMsg = [];
			foreach ($changeResult->getErrors() as $error)
			{
				$errorsMsg[] = $error->getMessage();
			}

			$errorMsgDesc = htmlspecialcharsbx(implode(', ', $errorsMsg));
			Logger::logErrors([new Error("Unhandled error while change dashboard (ID: {$dashboard->getId()}) title: {$errorMsgDesc}")]);

			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_UNHANDLED') ?? '';

			return $result->addError(new UserFacingError($errorMsg));
		}

		return $result;
	}

	public function addToTopMenuAction(int $dashboardId, CurrentUser $user): ?bool
	{
		$userId = $user->getId();
		if (!$userId)
		{
			return null;
		}
		$topMenuDashboards = \CUserOptions::GetOption(
			'biconnector',
			self::TOP_MENU_DASHBOARDS_OPTION_NAME,
			[],
			$userId,
		);

		if (in_array($dashboardId, $topMenuDashboards, true))
		{
			return true;
		}

		array_unshift($topMenuDashboards, $dashboardId);
		\CUserOptions::setOption(
			category: 'biconnector',
			name: self::TOP_MENU_DASHBOARDS_OPTION_NAME,
			value: $topMenuDashboards,
			user_id: $userId,
		);

		return true;
	}

	/**
	 * Publish or make draft dashboards
	 *
	 * @param Model\Dashboard $dashboard
	 * @param bool $publish
	 *
	 * @return bool|null
	 * @throws \Bitrix\Main\Access\Exception\UnknownActionException
	 */
	public function toggleDraftAction(Model\Dashboard $dashboard, bool $publish): ?bool
	{
		$accessItem = DashboardAccessItem::createFromEntity($dashboard);
		$accessController = AccessController::getCurrent();
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EDIT')));

			return null;
		}

		if (
			($publish && $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY)
			|| (!$publish && $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		)
		{
			return null;
		}

		$result = $dashboard->toggleDraft($publish);
		if (!$result->isSuccess())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_TOGGLE_DRAFT_ERROR')));

			return null;
		}

		return true;
	}

	public function deleteFromTopMenuAction(int $dashboardId, CurrentUser $user): ?bool
	{
		$userId = $user->getId();
		if (!$userId)
		{
			return null;
		}

		$topMenuDashboards = \CUserOptions::GetOption(
			'biconnector',
			self::TOP_MENU_DASHBOARDS_OPTION_NAME,
			[],
			$userId,
		);
		if (!in_array($dashboardId, $topMenuDashboards, true))
		{
			return true;
		}

		$topMenuDashboards = array_filter($topMenuDashboards, static fn ($item) => $item !== $dashboardId);
		\CUserOptions::setOption(
			category: 'biconnector',
			name: self::TOP_MENU_DASHBOARDS_OPTION_NAME,
			value: $topMenuDashboards,
			user_id: $userId,
		);

		return true;
	}

	public function pinAction(int $dashboardId, CurrentUser $user): ?bool
	{
		$userId = $user->getId();
		if (!$userId)
		{
			return null;
		}

		$pinnedDashboardIds = \CUserOptions::GetOption(
			'biconnector',
			self::PINNED_DASHBOARDS_OPTION_NAME,
			[],
			$userId,
		);
		if (!in_array($dashboardId, $pinnedDashboardIds, true))
		{
			$pinnedDashboardIds[] = $dashboardId;
		}

		\CUserOptions::setOption(
			category: 'biconnector',
			name: self::PINNED_DASHBOARDS_OPTION_NAME,
			value: $pinnedDashboardIds,
			user_id: $userId,
		);

		return true;
	}

	public function unpinAction(int $dashboardId, CurrentUser $user): ?bool
	{
		$userId = $user->getId();
		if (!$userId)
		{
			return null;
		}

		$pinnedDashboardIds = \CUserOptions::GetOption(
			'biconnector',
			self::PINNED_DASHBOARDS_OPTION_NAME,
			[],
			$userId,
		);
		$pinnedDashboardIds = array_filter($pinnedDashboardIds, static fn ($item) => $item !== $dashboardId);

		\CUserOptions::setOption(
			category: 'biconnector',
			name: self::PINNED_DASHBOARDS_OPTION_NAME,
			value: $pinnedDashboardIds,
			user_id: $userId,
		);

		return true;
	}

	public function getExportDataAction(Model\Dashboard $dashboard): ?array
	{
		if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EXPORT')));

			return null;
		}

		$scopesNotToExport = Model\SupersetScopeTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=DASHBOARD_ID' => $dashboard->getId(),
				'%=SCOPE_CODE' => 'automated_solution_%',
			],
			'limit' => 1,
		])
			->fetch()
		;
		$issetScopeNotToExport = !empty($scopesNotToExport);

		$currentGroup = SupersetDashboardGroupBindingTable::getList([
			'select' => ['GROUP.ID', 'GROUP.NAME'],
			'filter' => [
				'DASHBOARD_ID' => $dashboard->getId(),
				'GROUP.TYPE' => SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM,
			],
			'limit' => 1,
		])
			->fetchObject()
			?->getGroup()
			?->collectValues()
		;

		if (empty($currentGroup))
		{
			$currentGroup = null;
		}
		else
		{
			$currentGroup = \Bitrix\Main\Engine\Response\Converter::toJson()->process($currentGroup);
		}

		return [
			'title' => htmlspecialcharsbx($dashboard->getTitle()),
			'type' => $dashboard->getType(),
			'appId' => $dashboard->getAppId(),
			'group' => $currentGroup ?? null,
			'issetScopeNotToExport' => $issetScopeNotToExport,
		];
	}

	/**
	 * Creates a share link for the dashboard.
	 *
	 * @param Model\Dashboard $dashboard
	 * @param string $password
	 * @param string|null $dateEnd
	 * @param array|null $externalFilterValues Locked external filter values.
	 *        Format: {filterId: {value: [...], label: '...'}, ...}
	 * @param string $autoSave 'Y' for a save of the popup fields: a link switched off by an administrator stays off.
	 * @return array|null
	 */
	public function createShareAction(
		Model\Dashboard $dashboard,
		string $password,
		string $dateEnd,
		?string $externalFilterValuesJson = null,
		?array $urlParameterValues = null,
		string $autoSave = 'N',
	): ?array
	{
		$externalFilterValues = null;
		if ($externalFilterValuesJson !== null && $externalFilterValuesJson !== '')
		{
			$externalFilterValues = \Bitrix\Main\Web\Json::decode($externalFilterValuesJson);
		}

		$accessController = AccessController::getCurrent();
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_SHARE')));

			return null;
		}

		if (!$accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		if (mb_strlen($password) < 8 || mb_strlen($password) > 32)
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_SHARE_PASSWORD_LENGTH_ERROR')));

			return null;
		}

		$dateExpire = new DateTime($dateEnd, 'Y-m-d H:i:s');
		if (\CTimeZone::Enabled())
		{
			$diff = \CTimeZone::GetOffset(null, false, $dateExpire);
			if ($diff != 0)
			{
				$dateExpire->add(($diff > 0 ? '-' : '') . 'PT' . abs($diff) . 'S');
			}
		}

		$currentUserId = (int)CurrentUser::get()->getId();

		// No external filter values from frontend (e.g. sharing from grid without iframe).
		// Extract defaults from dashboard's native filter config in Superset.
		if ($externalFilterValues === null)
		{
			$superset = new SupersetController();
			$proxyDashboard = $superset->getDashboardRepository()->getById($dashboard->getId(), true);
			if ($proxyDashboard)
			{
				$externalFilterValues = RlsRuleBuilder::extractDefaultsFromConfig(
					$proxyDashboard->getNativeFiltersConfig(),
					$currentUserId,
				);
			}
		}

		$command = new CreateShareCommand(
			dashboardId: $dashboard->getId(),
			createdById: $currentUserId,
			password: $password,
			dateExpire: $dateExpire,
			externalFilterValues: $externalFilterValues,
			urlParameterValues: $urlParameterValues,
			isAutoSave: $autoSave === 'Y',
		);

		$result = $command->run();
		if ($result->getErrorCollection()->getErrorByCode(CreateShareCommandHandler::ERROR_SHARE_FORBIDDEN))
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_SHARE_FORBIDDEN'),
				CreateShareCommandHandler::ERROR_SHARE_FORBIDDEN,
			));

			return null;
		}

		if ($result->getErrorCollection()->getErrorByCode(CreateShareCommandHandler::ERROR_SHARE_DISABLED_BY_ADMIN))
		{
			$state = ServiceLocator::getInstance()
				->get('biconnector.provider.share')
				->getAuthorShareState($dashboard->getId(), $currentUserId)
			;
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_SHARE_DISABLED_BY_ADMIN'),
				CreateShareCommandHandler::ERROR_SHARE_DISABLED_BY_ADMIN,
				['disabledByAdmin' => $state['disabledByAdmin'] ?? null],
			));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$share = $result->getShare();

		$request = Application::getInstance()->getContext()->getRequest();
		$scheme = $request->isHttps() ? 'https' : 'http';
		$host = $request->getHttpHost();
		$shareUrl = $scheme . '://' . $host . '/pub/bi/dashboard/' . $share->getToken() . '/';

		return [
			'id' => $share->getId(),
			'token' => $share->getToken(),
			'url' => $shareUrl,
		];
	}

	public function deactivateShareAction(Model\Dashboard $dashboard): ?bool
	{
		$accessController = AccessController::getCurrent();
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_SHARE')));

			return null;
		}

		if (!$accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		$command = new DeactivateShareCommand(
			dashboardId: $dashboard->getId(),
			userId: $currentUserId,
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	public function deleteOwnShareAction(Model\Dashboard $dashboard): ?bool
	{
		$accessController = AccessController::getCurrent();
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_SHARE')));

			return null;
		}

		if (!$accessController->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		// Kept for old clients: the link is switched off, the record stays for the registry.
		$result = (new DeactivateShareCommand(
			dashboardId: $dashboard->getId(),
			userId: (int)CurrentUser::get()->getId(),
		))->run();

		if (!$result->isSuccess())
		{
			Logger::logErrors($result->getErrors());
			$this->addError(new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_SHARE_REVOKE_ERROR')));

			return null;
		}

		return true;
	}

	public function saveScreenshotAction(Model\Dashboard $dashboard, string $content, string $fileType): ?array
	{
		$result = ScreenshotExporter::saveDashboardScreenshot($dashboard, $content, $fileType);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$filePath = $result->getData()['filePath'];

		return [
			'filePath' => $filePath,
		];
	}

	/**
	 * Spends the single show of the BitrixGPT announcement for the current user.
	 * Parallel tabs race for the same personal mark, so the check and the write go under a user lock.
	 */
	public function claimBitrixGptBiAnnouncementAction(CurrentUser $user): array
	{
		$userId = (int)$user->getId();
		if ($userId <= 0)
		{
			return ['claimed' => false];
		}

		// A page rendered before the campaign ended could still be open: the server has the final
		// say on the date, so a stale tab neither shows the announcement nor spends the show.
		if (BitrixGptBiAnnouncement::isTerminated())
		{
			return ['claimed' => false];
		}

		$connection = Application::getConnection();
		$lockKey = self::BITRIXGPT_BI_ANNOUNCE_LOCK_PREFIX . $userId;
		if (!$connection->lock($lockKey, 0))
		{
			return ['claimed' => false];
		}

		try
		{
			if (BitrixGptBiAnnouncement::isShownForUser($userId))
			{
				return ['claimed' => false];
			}

			$saved = \CUserOptions::setOption(
				category: BitrixGptBiAnnouncement::USER_OPTION_CATEGORY,
				name: BitrixGptBiAnnouncement::USER_OPTION_NAME,
				value: time(),
				bCommon: false,
				user_id: $userId,
			);

			return ['claimed' => $saved === true];
		}
		finally
		{
			$connection->unlock($lockKey);
		}
	}
}
