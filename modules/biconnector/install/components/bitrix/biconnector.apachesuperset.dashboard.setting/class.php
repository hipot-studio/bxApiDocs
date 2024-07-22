<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\IconController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Section\EntityEditorSection;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\EntityEditorController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\SettingsComponentController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\SettingsPanel;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Type\Date;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;
use Bitrix\Bitrix24\Feature;

Loader::includeModule("biconnector");

class ApacheSupersetDashboardSettingComponent
	extends CBitrixComponent
	implements Controllerable, Errorable
{
	use ErrorableImplementation;

	private ?Dashboard $dashboard;

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
		$this->initDashboard();
		if ($this->dashboard === null)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_DASHBOARD_NOT_FOUND');
			$this->includeComponentTemplate();

			return;
		}

		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			foreach ($checkingResult->getErrorMessages() as $message)
			{
				$this->arResult['ERROR_MESSAGES'][] = $message;
			}

			$this->includeComponentTemplate();

			return;
		}

		$this->initSettingsPanel();
		$this->arResult['TITLE'] = $this->getTitle();

		Toolbar::addButton(
			new Buttons\Button(
				[
					"color" => Buttons\Color::LIGHT_BORDER,
					"size"  => Buttons\Size::MEDIUM,
					"click" => new Buttons\JsCode(
						"top.BX.Helper.show('redirect=detail&code=20337242');"
					),
					"text" => Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_DASHBOARD_HELP')
				]
			)
		);

		$this->includeComponentTemplate();
	}

	private function initSettingsPanel(): void
	{
		$ajaxData = [
			'COMPONENT_NAME' => $this->getName(),
			'ACTION_NAME' => 'save',
			'SIGNED_PARAMETERS' => $this->getSignedParameters(),
		];

		$settingsPanel =
			(new SettingsPanel('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS'))
			->addController(
				$this->getController(),
				new IconController('ICON_CONTROLLER')
			)
			->addSection($this->getFilterSection())
			->setAjaxData($ajaxData)
		;

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $this->dashboard->getId(),
			'TYPE' => $this->dashboard->getType(),
			'OWNER_ID' => $this->dashboard->getOrmObject()->getOwnerId(),
		]);
		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem))
		{
			$settingsPanel->addSection($this->getOwnerSection());
		}

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EDIT_SCOPE))
		{
			$settingsPanel->addSection($this->getScopeSection());
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
		$result = new Result();

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_MSGVER_1')));

			return $result;
		}

		if (
			!AccessController::getCurrent()->checkByItemId(
				ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
				$this->dashboard->getId()
			)
		)
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_DASHBOARD')));

			return $result;
		}

		if (
			!Loader::includeModule('bitrix24')
			|| !Feature::isFeatureEnabled('bi_constructor')
		)
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_FEATURE_UNAVAILABLE')));

			return $result;
		}

		return $result;
	}

	private function initDashboard(): void
	{
		$superset = new SupersetController(Integrator::getInstance());
		$this->dashboard = $superset->getDashboardRepository()->getById((int)$this->arParams['DASHBOARD_ID']);
	}

	private function getFilterSection(): EntityEditorSection
	{
		$dateFilterSection = (new EntityEditorSection(
			name: 'DASHBOARD_FILTER',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_SECTION_PERIOD_TITLE'),
		))
			->setIconClass('--calendar-1')
		;

		if ($this->dashboard !== null)
		{
			$dateFilterSection->addField(new Field\DashboardPeriodFilterField(
				id: 'DASHBOARD_FILTER',
				dashboard: $this->dashboard,
			));
		}
		else
		{
			$dateFilterSection->addField(new Field\PeriodFilterField(
				id: 'DASHBOARD_FILTER',
			));
		}

		return $dateFilterSection;
	}

	private function getOwnerSection(): EntityEditorSection
	{
		return (new EntityEditorSection(
			name: 'OWNER_ID',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_SECTION_OWNER_TITLE'),
		))
			->addField(new Field\OwnerField(
				id: 'OWNER_ID',
				dashboard: $this->dashboard,
			))
			->setIconClass('--person-check')
		;
	}

	private function getScopeSection(): EntityEditorSection
	{
		return (new EntityEditorSection(
			name: 'SCOPE',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_SECTION_SCOPE_TITLE'),
		))
			->addField(new Field\ScopeField(
				id: 'SCOPE',
				dashboard: $this->dashboard,
			))
			->setIconClass('--opened-eye')
		;
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
		$result = [];

		if ($data['FILTER_PERIOD'] === EmbeddedFilter\DateTime::PERIOD_DEFAULT)
		{
			$dashboardObject->setDateFilterStart(null);
			$dashboardObject->setDateFilterEnd(null);
			$dashboardObject->setFilterPeriod(null);

			$result['FILTER_PERIOD'] = EmbeddedFilter\DateTime::PERIOD_DEFAULT;
		}
		elseif ($data['FILTER_PERIOD'] === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			$startTime = null;
			$endTime = null;

			try
			{
				$startTime = new Date($data['DATE_FILTER_START']);
				$endTime = new Date($data['DATE_FILTER_END']);
			}
			catch (\Bitrix\Main\ObjectException)
			{
				$error = new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_INVALID_RANGE'));
				$this->errorCollection->setError($error);

				return null;
			}

			$dashboardObject->setDateFilterStart($startTime);
			$dashboardObject->setDateFilterEnd($endTime);
			$dashboardObject->setFilterPeriod(EmbeddedFilter\DateTime::PERIOD_RANGE);
			$result['DATE_FILTER_START'] = $startTime;
			$result['DATE_FILTER_END'] = $endTime;
			$result['FILTER_PERIOD'] = EmbeddedFilter\DateTime::PERIOD_RANGE;
		}
		else
		{
			$period = EmbeddedFilter\DateTime::getDefaultPeriod();
			$innerPeriod = $data['FILTER_PERIOD'] ?? '';
			if (is_string($innerPeriod) && EmbeddedFilter\DateTime::isAvailablePeriod($innerPeriod))
			{
				$period = $innerPeriod;
			}
			$dashboardObject->setFilterPeriod($period);
			$result['FILTER_PERIOD'] = $period;
		}

		if (isset($data['OWNER_ID']))
		{
			$accessItem = DashboardAccessItem::createFromArray([
				'ID' => $dashboardObject->getId(),
				'TYPE' => $dashboardObject->getType(),
				'OWNER_ID' => $dashboardObject->getOwnerId(),
			]);
			if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem))
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_DASHBOARD')),
				);

				return null;
			}

			$integrator = Integrator::getInstance();
			$newOwnerId = (int)$data['OWNER_ID'];
			if ($newOwnerId <= 0)
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_OWNER_ERROR_NOT_SELECTED')),
				);

				return null;
			}

			$userTo = (new SupersetUserRepository)->getById($newOwnerId);
			if (!$userTo)
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_OWNER_ERROR_NOT_FOUND')),
				);

				return null;
			}

			if (!$userTo->clientId)
			{
				$superset = new SupersetController($integrator);
				$createResult = $superset->createUser($newOwnerId);
				if (!$createResult->isSuccess())
				{
					$this->errorCollection->setError(
						new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_OWNER_ERROR_CREATE_USER')),
					);

					return null;
				}
				$userTo = $createResult->getData()['user'];
			}

			$currentOwnerId = $dashboardObject->getOwnerId();
			$userFrom = (new SupersetUserRepository)->getById($currentOwnerId);
			if (!$userFrom)
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_OWNER_ERROR_CREATE_USER')),
				);

				return null;
			}

			$setOwnerResult = $integrator->changeDashboardOwner($dashboardObject->getExternalId(), $userFrom, $userTo);
			if ($setOwnerResult->hasErrors())
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_OWNER_ERROR_CREATE_USER')),
				);

				return null;
			}
			$dashboardObject->setOwnerId((int)$data['OWNER_ID']);
			$result['OWNER_ID'] = (int)$data['OWNER_ID'];
		}

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EDIT_SCOPE))
		{
			$scopes = $data['SCOPE'] ?? [];
			$saveScopeResult = ScopeService::getInstance()->saveDashboardScopes($dashboardObject->getId(), $scopes);
			if (!$saveScopeResult->isSuccess())
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_SCOPE_ERROR')),
				);

				return null;
			}
		}

		$dashboardObject->save();

		return $result;
	}
}
