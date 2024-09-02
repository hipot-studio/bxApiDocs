<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTagTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetTagTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\BIConnector\Superset\Grid\Settings\DashboardSettings;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Uri;

class Dashboard extends Controller
{
	private const TOP_MENU_DASHBOARDS_OPTION_NAME = 'top_menu_dashboards';
	private const PINNED_DASHBOARDS_OPTION_NAME = 'grid_pinned_dashboards';

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

	public function getPrimaryAutoWiredParameter(): ExactParameter
	{
		return new ExactParameter(
			Model\Dashboard::class,
			'dashboard',
			function($className, $id)
			{
				$superset = new SupersetController(Integrator::getInstance());
				$dashboard = $superset->getDashboardRepository()->getById($id);
				if (!$dashboard)
				{
					$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

					return null;
				}

				return $dashboard;
			}
		);
	}

	public function copyAction(Model\Dashboard $dashboard): ?array
	{
		if (!AccessController::getCurrent()->checkByItemId(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $dashboard->getId()))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_COPY')));

			return null;
		}

		$integrator = Integrator::getInstance();
		$superset = new SupersetController($integrator);
		$externalId = $dashboard->getExternalId();
		$dashboardId = $dashboard->getId();
		if ((int)$externalId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

			return null;
		}
		$newTitle = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_COPIED_DASHBOARD_TITLE', [
			'#DASHBOARD_TITLE#' => $dashboard->getTitle(),
		]);
		$response = $integrator->copyDashboard($externalId, $newTitle);
		/** @var array $data */
		$data = $response->getData();
		if (!$data)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_COPY_ERROR')));

			return null;
		}

		$copiedDashboardExternalId = (int)$data['id'];
		$title = $data['title'] ?? '';
		$filter = new EmbeddedFilter\DateTime($dashboard);

		$addData = [
			'EXTERNAL_ID' => $copiedDashboardExternalId,
			'TITLE' => $title,
			'SOURCE_ID' => $dashboard->getId(),
			'APP_ID' => $dashboard->getAppId(),
			'TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
			'CREATED_BY_ID' => $this->getCurrentUser()?->getId(),
			'OWNER_ID' => $this->getCurrentUser()?->getId(),
		];

		if ($dashboard->getField('FILTER_PERIOD'))
		{
			$addData['FILTER_PERIOD'] = $filter->getPeriod();
		}

		if ($filter->getPeriod() === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			$addData['DATE_FILTER_START'] = new Date($filter->getDateStart());
			$addData['DATE_FILTER_END'] = new Date($filter->getDateEnd());
		}

		$addResult = SupersetDashboardTable::add($addData);
		if (!$addResult->isSuccess())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_COPY_ERROR')));

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

		$newDashboard = $superset->getDashboardRepository()->getById($copiedDashboardId);
		if ($newDashboard)
		{
			foreach ($dashboard->getOrmObject()->fillTags() as $tag)
			{
				SupersetDashboardTagTable::add([
					'TAG_ID' => $tag->getId(),
					'DASHBOARD_ID' => $newDashboard->getId(),
				]);
			}
			$scopes = ScopeService::getInstance()->getDashboardScopes($dashboard->getId());
			ScopeService::getInstance()->saveDashboardScopes($copiedDashboardId, $scopes);

			$gridRow = $this->prepareGridRow($newDashboard);
			$data['id'] = $copiedDashboardId;
			$data['detail_url'] = "/bi/dashboard/detail/{$copiedDashboardId}/";
			$data['columns'] = $gridRow['columns'];
			$data['actions'] = $gridRow['actions'];

			return ['dashboard' => $data];
		}

		return null;
	}

	public function exportAction(Model\Dashboard $dashboard, bool $withSettings): ?array
	{
		if (!MarketDashboardManager::getInstance()->isExportEnabled())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EXPORT')));

			return null;
		}

		if (!AccessController::getCurrent()->checkByItemId(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $dashboard->getId()))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EXPORT')));

			return null;
		}

		$integrator = Integrator::getInstance();
		$externalDashboardId = $dashboard->getExternalId();
		if ((int)$externalDashboardId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ERROR_NOT_FOUND')));

			return null;
		}

		$dashboardSettings = [];
		if ($withSettings)
		{
			$filterPeriod = $dashboard->getOrmObject()->getFilterPeriod();
			if (!$filterPeriod)
			{
				$filterPeriod = EmbeddedFilter\DateTime::PERIOD_DEFAULT;
			}
			$dashboardSettings = [
				'period' => [
					'FILTER_PERIOD' => $filterPeriod,
					'DATE_FILTER_START' => $dashboard->getOrmObject()->getDateFilterStart(),
					'DATE_FILTER_END' => $dashboard->getOrmObject()->getDateFilterEnd(),
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
		}

		$response = $integrator->exportDashboard($externalDashboardId, $dashboardSettings);
		if ($response->hasErrors())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_EXPORT_ERROR')));

			return null;
		}

		/** @var array $data */
		$data = $response->getData();
		if ($data)
		{
			$contentSize = $data['contentSize'];
			$filePath = $data['filePath'];
			if ((int)$contentSize <= 0)
			{
				$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_EXPORT_ERROR')));

				return null;
			}

			return ['filePath' => $filePath];
		}

		return null;
	}

	public function deleteAction(Model\Dashboard $dashboard): ?bool
	{
		$dashboardId = $dashboard->getId();
		if (!AccessController::getCurrent()->checkByItemId(ActionDictionary::ACTION_BIC_DASHBOARD_DELETE, $dashboardId))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_DELETE')));

			return null;
		}

		$hasCopiedDashboards = SupersetDashboardTable::getList([
			'select' => ['SOURCE_ID'],
			'filter' => [
				'=SOURCE_ID' => $dashboardId,
			],
		])->fetch();

		if ($hasCopiedDashboards)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR_COPIED')));

			return null;
		}

		if ($dashboard->isSystemDashboard())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR_SYSTEM')));

			return null;
		}

		if ($dashboard->isMarketDashboard())
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

		if ($dashboard->isEditable())
		{
			$externalDashboardId = $dashboard->getExternalId();
			$response = Integrator::getInstance()->deleteDashboard([$externalDashboardId]);
			if ($response->hasErrors())
			{
				if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
				{
					SupersetDashboardTable::delete($dashboardId);

					return true;
				}
				$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_DELETE_ERROR')));

				return null;
			}

			SupersetDashboardTable::delete($dashboardId);
		}

		return true;
	}

	public function restartImportAction(Model\Dashboard $dashboard): ?array
	{
		if (
			$dashboard->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_FAILED
			|| !SupersetInitializer::isSupersetReady()
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

	public function getDashboardEmbeddedDataAction(Model\Dashboard $dashboard): ?array
	{
		$accessController = AccessController::getCurrent();
		if (!$accessController->checkByItemId(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard->getId()))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_VIEW')));

			return null;
		}

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $dashboard->getId(),
			'TYPE' => $dashboard->getType(),
			'OWNER_ID' => $dashboard->getField('OWNER_ID'),
		]);

		$canExport = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $accessItem);

		$canEdit = false;
		if (
			$dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
			|| $dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET
		)
		{
			$canEdit = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $accessItem);
		}
		else if ($dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$canEdit = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem);
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
				'dashboardUrl' => "/bi/dashboard/detail/{$dashboard->getId()}/",
				'appId' => $dashboard->getAppId(),
				'nativeFilters' => $dashboard->getNativeFilter(),
				'canExport' => $canExport ? 'Y' : 'N',
				'canEdit' => $canEdit ? 'Y' : 'N',
			],
		];
	}

	public function setDashboardTagsAction(Model\Dashboard $dashboard, array $tags = []): ?bool
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_TAG_MODIFY))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_TAG_MODIFY')));

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

	public function createEmptyDashboardAction(): ?array
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_CREATE))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_CREATE')));

			return null;
		}

		$name = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_EMPTY_DASHBOARD_NAME');

		$dashboard = SupersetDashboardTable::getRow([
			'select' => ['TITLE'],
			'filter' => ['%TITLE' => $name],
			'order' => ['ID' => 'DESC'],
		]);
		if ($dashboard)
		{
			$number = 0;

			$currentTitle = $dashboard['TITLE'];
			preg_match_all('/\d+/', $currentTitle, $matches);
			if (!empty($matches[0]))
			{
				$number = (int)$matches[0][0];
			}

			$number++;
			$name .= " ($number)";
		}

		$integrator = Integrator::getInstance();
		$response = $integrator->createEmptyDashboard([
			'name' => $name,
		]);

		if ($response->getErrors())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_CREATE_EMPTY_ERROR')));

			return null;
		}

		$data = $response->getData();
		if (empty($data['body']))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_CREATE_EMPTY_ERROR')));

			return null;
		}

		$addDashboardResult = SupersetDashboardTable::add([
			'EXTERNAL_ID' => $data['body']['id'],
			'TITLE' => $data['body']['result']['dashboard_title'],
			'TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM,
			'CREATED_BY_ID' => $this->getCurrentUser()?->getId(),
			'OWNER_ID' => $this->getCurrentUser()?->getId(),
		]);
		if (!$addDashboardResult->isSuccess())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_CREATE_EMPTY_ERROR')));

			return null;
		}

		$data = [];
		$superset = new SupersetController(Integrator::getInstance());
		$dashboard = $superset->getDashboardRepository()->getById($addDashboardResult->getId());
		if ($dashboard)
		{
			$gridRow = $this->prepareGridRow($dashboard);

			$data['id'] = $addDashboardResult->getId();
			$data['title'] = $dashboard->getTitle();

			$data['columns'] = $gridRow['columns'];
			$data['actions'] = $gridRow['actions'];


			return ['dashboard' => $data];
		}

		return null;
	}

	/**
	 * @example BX.ajax.runAction('biconnector.dashboard.getEditUrl', {data: {editUrl: '', dashboardId: 192}});
	 *
	 * @param int $dashboardId
	 * @param string $editUrl
	 *
	 * @return string|null
	 */
	public function getEditUrlAction(int $dashboardId, string $editUrl): ?string
	{
		$accessItem = DashboardAccessItem::createFromId($dashboardId);

		$canEdit = AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem);
		if (!$canEdit)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EDIT')));

			return null;
		}

		$loginUrl = (new SupersetController(Integrator::getInstance()))->getLoginUrl();

		if ($loginUrl)
		{
			$url = new Uri($loginUrl);
			$url->addParams([
				'next' => $editUrl,
			]);

			return $url->getLocator();
		}

		return $editUrl;
	}

	private function prepareGridRow(Model\Dashboard $dashboard): array
	{
		$supersetController = new SupersetController(Integrator::getInstance());

		$settings = new DashboardSettings([
			'ID' => 'biconnector_superset_dashboard_grid',
			'IS_SUPERSET_AVAILABLE' => $supersetController->isExternalServiceAvailable(),
		]);

		$grid = new DashboardGrid($settings);

		$dashboardFields = $dashboard->toArray();
		$tagList = [];
		$tags = $dashboard->getOrmObject()->fillTags();
		foreach ($tags->getAll() as $tag)
		{
			$tagList[] = [
				'ID' => $tag->getId(),
				'TITLE' => $tag->getTitle(),
			];
		}
		$dashboardFields['TAGS'] = $tagList;
		$dashboardFields['SCOPE'] = ScopeService::getInstance()->getDashboardScopes($dashboard->getId());

		$result = $grid->getRows()->prepareRows([$dashboardFields]);
		$result = current($result);
		if (is_array($result))
		{
			$converter = new Converter(Converter::OUTPUT_JSON_FORMAT);
			$result['actions'] = $converter->process($result['actions']);

			return $result;
		}

		return [];
	}

	public function renameAction(Model\Dashboard $dashboard, string $title): void
	{
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $dashboard->getId(),
			'TYPE' => $dashboard->getType(),
			'OWNER_ID' => $dashboard->getField('OWNER_ID'),
		]);

		$canEdit = AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem);
		if (!$canEdit)
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EDIT')));

			return;
		}

		$supersetController = new SupersetController(Integrator::getInstance());
		if (!$supersetController->isSupersetEnabled() || !$supersetController->isExternalServiceAvailable())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_UNAVAILABLE')));

			return;
		}

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
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_NOT_FOUND');

			return $result->addError(new Error($errorMsg));
		}

		if (empty($newTitle))
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_NOT_EMPTY');

			return $result->addError(new Error($errorMsg));
		}

		if ($dashboard->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_READY)
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_NOT_READY');

			return $result->addError(new Error($errorMsg));
		}

		if ($dashboard->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_ONLY_CUSTOM');

			return $result->addError(new Error($errorMsg));
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

			$errorMsg = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_RENAME_ERROR_CANNOT_EDIT_TITLE_UNHANDLED');

			return $result->addError(new Error($errorMsg));
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
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $dashboard->getId(),
			'TYPE' => $dashboard->getType(),
			'OWNER_ID' => $dashboard->getField('OWNER_ID'),
		]);

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $accessItem))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_ACCESS_ERROR_EXPORT')));

			return null;
		}

		$filterPeriod = $dashboard->getNativeFilterFields();
		if (!$dashboard->getOrmObject()->getFilterPeriod())
		{
			$period = Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_EXPORT_DATA_PERIOD_DEFAULT');
		}
		else if ($filterPeriod['FILTER_PERIOD'] !== EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			$period = EmbeddedFilter\DateTime::getPeriodName($filterPeriod['FILTER_PERIOD']) ?? '';
		}
		else
		{
			$dateStart = $filterPeriod['DATE_FILTER_START'];
			if ($dateStart instanceof Date)
			{
				$dateStart->toString();
			}
			$dateEnd = $filterPeriod['DATE_FILTER_END'];
			if ($dateEnd instanceof Date)
			{
				$dateEnd->toString();
			}
			$period = "{$dateStart} - {$dateEnd}";
		}

		$scopeCodes = Model\SupersetScopeTable::getList([
			'select' => ['*', 'IS_AUTOMATED_SOLUTION'],
			'filter' => [
				'=DASHBOARD_ID' => $dashboard->getId(),
			],
			'runtime' => [
				new ExpressionField(
					'IS_AUTOMATED_SOLUTION',
					"CASE WHEN %s LIKE 'automated_solution_%%' THEN 1 ELSE 0 END",
					['SCOPE_CODE'],
					['data_type' => 'integer']
				),
			],
		])
			->fetchAll()
		;
		$scopesToExport = array_filter($scopeCodes, static fn ($scope) => !$scope['IS_AUTOMATED_SOLUTION']);
		$scopesNotToExport = array_filter($scopeCodes, static fn ($scope) => $scope['IS_AUTOMATED_SOLUTION']);

		$scopeNamesToExport = ScopeService::getInstance()->getScopeNameList(array_column($scopesToExport, 'SCOPE_CODE'));
		$scopeNamesNotToExport = ScopeService::getInstance()->getScopeNameList(array_column($scopesNotToExport, 'SCOPE_CODE'));

		return [
			'title' => htmlspecialcharsbx($dashboard->getTitle()),
			'period' => $period,
			'scopesToExport' => htmlspecialcharsbx(implode(', ', $scopeNamesToExport)),
			'scopesNotToExport' => htmlspecialcharsbx(implode(', ', $scopeNamesNotToExport)),
			'type' => $dashboard->getType(),
			'appId' => $dashboard->getAppId(),
		];
	}
}
