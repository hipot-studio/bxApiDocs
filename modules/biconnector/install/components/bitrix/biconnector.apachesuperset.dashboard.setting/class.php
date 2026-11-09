<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\BIConnector\Internal\Services\DashboardSettings\DashboardSettingsMode;
use Bitrix\BIConnector\Internal\Services\DashboardSettings\DashboardSettingsModeResolver;
use Bitrix\BIConnector\Internal\Services\DashboardSettings\RequiredParamListProvider;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseLock;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\IconController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Section\EntityEditorSection;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\EntityEditorController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\SettingsComponentController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\SettingsPanel;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;
use Bitrix\BIConnector\Configuration\Feature;

Loader::includeModule('biconnector');

/**
 * @todo Migrate to the biconnector.settings-panel (TS cards) extension and drop
 *       Bitrix\BIConnector\Superset\UI\SettingsPanel together with the biconnector.apachesuperset.settings.panel
 *       component. This component is the last consumer of the EntityEditor-based panel; all other panels have
 *       already moved to the new extension.
 */
class ApacheSupersetDashboardSettingComponent
	extends CBitrixComponent
	implements Controllerable, Errorable
{
	use ErrorableImplementation;

	private ?Dashboard $dashboard;
	private ?DashboardSettingsModeResolver $modeResolver = null;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errorCollection = new ErrorCollection();
	}

	public function configureActions()
	{
		return [];
	}

	protected function listKeysSignedParameters()
	{
		return ['DASHBOARD_ID'];
	}

	public function onPrepareComponentParams($arParams)
	{
		$arParams['DASHBOARD_ID'] = (int)($arParams['DASHBOARD_ID'] ?? 0);

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		$this->prepareResult();
		$this->includeComponentTemplate();
	}

	protected function prepareResult(): void
	{
		$this->initDashboard();
		if ($this->dashboard === null)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_DASHBOARD_NOT_FOUND');

			return;
		}

		$mode = $this->getModeResolver()->resolve(DashboardAccessItem::createFromEntity($this->dashboard));
		$checkingResult = $this->checkOpenAccess($mode);
		if (!$checkingResult->isSuccess())
		{
			foreach ($checkingResult->getErrorMessages() as $message)
			{
				$this->arResult['ERROR_MESSAGES'][] = $message;
			}

			return;
		}

		$this->arResult['SETTINGS_MODE'] = $mode->value;
		if ($mode === DashboardSettingsMode::Reduced)
		{
			$this->arResult['REDUCED_SETTINGS'] = [
				'dashboardId' => $this->dashboard->getId(),
				'dashboard' => $this->getModeResolver()->getBaseInfo($this->dashboard),
				'shareSetting' => $this->getModeResolver()->getShareSettingState($this->dashboard->getId()),
			];
		}
		else
		{
			$this->initSettingsPanel();
		}

		$this->arResult['TITLE'] = $this->getTitle();

		Toolbar::addButton(
			new Buttons\Button(
				[
					'color' => Buttons\Color::LIGHT_BORDER,
					'size'  => Buttons\Size::MEDIUM,
					'click' => new Buttons\JsCode(
						"top.BX.Helper.show('redirect=detail&code=28376530');",
					),
					'text' => Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_DASHBOARD_HELP'),
					'dataset' => [
						'toolbar-collapsed-icon' => Buttons\Icon::INFO,
					],
				],
			),
		);
	}

	private function initSettingsPanel(): void
	{
		$ajaxData = [
			'COMPONENT_NAME' => $this->getName(),
			'ACTION_NAME' => 'save',
			'SIGNED_PARAMETERS' => $this->getSignedParameters(),
		];

		$settingsPanel
			= (new SettingsPanel('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS'))
				->addController(
					$this->getController(),
					new IconController('ICON_CONTROLLER'),
				)
				->setAjaxData($ajaxData)
		;

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			$settingsPanel->addSection($this->getGroupsSection());
		}

		$shareSettingState = $this->getModeResolver()->getShareSettingState($this->dashboard->getId());
		if ($shareSettingState !== null)
		{
			$settingsPanel->addSection($this->getShareSettingSection($shareSettingState));
		}

		if (isset($this->arParams['DASHBOARD_ID']))
		{
			$settingsPanel->setEntityId($this->arParams['DASHBOARD_ID']);
		}

		$this->arResult['SETTINGS_PANEL'] = $settingsPanel;
	}

	private function getController(): EntityEditorController
	{
		return
			(new SettingsComponentController('SETTING_COMPONENT_CONTROLLER'))
				->setConfig([
					'dashboardAnalyticInfo' => $this->getDashboardInfo(),
				])
		;
	}

	private function checkAccess(): Result
	{
		$canModifySettings = AccessController::getCurrent()->checkByEntity(
			ActionDictionary::ACTION_BIC_DASHBOARD_MODIFY_SETTINGS,
			$this->dashboard,
		);

		return $this->checkAccessWith($canModifySettings);
	}

	/**
	 * Opening the settings also lets in a public links controller who can view the dashboard
	 * or reaches it by its public links (reduced mode).
	 */
	private function checkOpenAccess(DashboardSettingsMode $mode): Result
	{
		return $this->checkAccessWith($mode !== DashboardSettingsMode::Denied);
	}

	private function checkAccessWith(bool $canOpenDashboardSettings): Result
	{
		$result = new Result();

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_MSGVER_1')));

			return $result;
		}

		if (!$canOpenDashboardSettings)
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_DASHBOARD')));

			return $result;
		}

		if (SelfHostedLicenseLock::isDashboardLocked())
		{
			$result->addError(new Error(SelfHostedLicenseLock::getRestrictionMessage()));

			return $result;
		}

		if (!Feature::isBuilderEnabled())
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_FEATURE_UNAVAILABLE')));

			return $result;
		}

		return $result;
	}

	private function initDashboard(): void
	{
		$superset = new SupersetController();
		$this->dashboard = $superset->getDashboardRepository()->getById((int)$this->arParams['DASHBOARD_ID']);
	}

	private function getGroupsSection(): EntityEditorSection
	{
		return (new EntityEditorSection(
			name: 'DASHBOARD_GROUPS',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_SECTION_GROUPS_TITLE'),
		))
			->addField(new Field\DashboardGroupsField(
				id: 'DASHBOARD_GROUPS',
				dashboard: $this->dashboard,
				requiredParamList: $this->getRequiredParamList(),
			))
			->setIconClass('--folder-empty')
		;
	}

	/**
	 * @return array|null Null when the filters of the dashboard could not be read: nothing is imposed then.
	 */
	private function getRequiredParamList(): ?array
	{
		return (new RequiredParamListProvider())->getForDashboard($this->dashboard);
	}

	/**
	 * @param array{isBanned: bool, canChange: bool} $state
	 */
	private function getShareSettingSection(array $state): EntityEditorSection
	{
		return (new EntityEditorSection(name: 'DASHBOARD_SHARE_SETTING'))
			->addField(new Field\DashboardShareSettingField(
				id: 'DASHBOARD_SHARE_SETTING',
				dashboardId: $this->dashboard->getId(),
				state: $state,
			))
		;
	}

	private function getModeResolver(): DashboardSettingsModeResolver
	{
		$this->modeResolver ??= new DashboardSettingsModeResolver();

		return $this->modeResolver;
	}

	private function getTitle(): string
	{
		return Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_TITLE_MSGVER_1', [
			'#DASHBOARD_TITLE#' => $this->dashboard->getTitle(),
		]);
	}

	private function getDashboardInfo(): ?array
	{
		if ($this->dashboard === null)
		{
			return null;
		}

		return [
			'id' => $this->dashboard->getId(),
			'appId' => $this->dashboard->getAppId(),
			'type' => $this->dashboard->getType(),
		];
	}

	public function saveAction(array $data): ?array
	{
		$this->initDashboard();
		if (!$this->dashboard)
		{
			$error = new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_INVALID_DASHBOARD'));
			$this->errorCollection->setError($error);

			return null;
		}

		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		$dashboardId = (int)$this->arParams['DASHBOARD_ID'];
		if ($dashboardId === 0)
		{
			$error = new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_INVALID_DASHBOARD'));
			$this->errorCollection->setError($error);

			return null;
		}

		$dashboardObject = $this->dashboard->getOrmObject();

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_MODIFY_SETTINGS))
		{
			$dashboardObject->unsetGroups();
			$groupIds = array_flip($data['DASHBOARD_PARAMETERS']['GROUPS'] ?? []);
			if (empty($groupIds) && !AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SECTION_EDIT_DASHBOARD_RIGHTS))
			{
				$error = new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_EMPTY_DASHBOARD_GROUP'));
				$this->errorCollection->setError($error);

				return null;
			}

			$allowedEditGroups = AccessController::getCurrent()->getAllowedGroupValue(ActionDictionary::ACTION_BIC_DASHBOARD_MODIFY_SETTINGS);
			$flippedAllowedEditGroups = array_flip($allowedEditGroups);

			$bindings = SupersetDashboardGroupBindingTable::getList([
				'filter' => ['DASHBOARD_ID' => $dashboardObject->getId()],
			])
				->fetchCollection()
			;

			foreach ($bindings as $binding)
			{
				$bindingGroupId = $binding->getGroupId();
				if (isset($groupIds[$bindingGroupId]))
				{
					unset($groupIds[$bindingGroupId]);
				}
				elseif (isset($flippedAllowedEditGroups[$bindingGroupId]))
				{
					$binding->delete();
				}
			}

			$addGroups = array_intersect(array_flip($groupIds), $allowedEditGroups);
			foreach ($addGroups as $groupId)
			{
				SupersetDashboardGroupBindingTable::add([
					'DASHBOARD_ID' => $dashboardObject->getId(),
					'GROUP_ID' => $groupId,
				]);
			}

			$scopes = $data['DASHBOARD_PARAMETERS']['SCOPE'] ?? [];
			$saveScopeResult = ScopeService::getInstance()->saveDashboardScopes($dashboardObject->getId(), $scopes);
			if (!$saveScopeResult->isSuccess())
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_SCOPE_ERROR')),
				);

				return null;
			}

			$params = $data['DASHBOARD_PARAMETERS']['PARAMS'] ?? [];
			// The form locks a required parameter, so only a crafted request reaches here without it.
			// Filters that could not be read impose nothing: Superset may be not installed or down.
			$requiredParamList = $this->getRequiredParamList() ?? [];
			$params = array_values(array_unique(array_merge($params, array_keys($requiredParamList))));
			$paramService = new UrlParameter\Service($dashboardObject);
			$saveParamsResult = $paramService->saveDashboardParams($params, $scopes);
			if (!$saveParamsResult->isSuccess())
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_PARAMS_ERROR')),
				);

				return null;
			}
		}

		$dashboardObject->save();

		return [];
	}
}
