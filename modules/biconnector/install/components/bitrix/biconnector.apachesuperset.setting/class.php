<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\IconController;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\KeyTable;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\KeyInfoField;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\DashboardLanguageField;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\TimeZoneField;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\GlobalSettingsButtonField;
use Bitrix\Intranet\Portal;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Section\EntityEditorSection;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\EntityEditorController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Controller\SettingsComponentController;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\PeriodFilterField;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\ClearCacheField;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\Field\DatasetTypingField;
use Bitrix\BIConnector\Superset\UI\SettingsPanel\SettingsPanel;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
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
use Bitrix\BIConnector\Configuration\Feature;

Loader::includeModule("biconnector");

class ApacheSupersetSettingComponent
	extends CBitrixComponent
	implements Controllerable, Errorable
{
	use ErrorableImplementation;

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
		return [];
	}

	public function executeComponent()
	{
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

		$this->arResult['TITLE'] = $this->getTitle();

		$this->initSettingsPanel();

		Toolbar::addButton(
			new Buttons\Button(
				[
					'color' => Buttons\Color::LIGHT_BORDER,
					'size'  => Buttons\Size::MEDIUM,
					'click' => new Buttons\JsCode(
						"top.BX.Helper.show('redirect=detail&code=20337242');"
					),
					'text' => Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_DASHBOARD_HELP'),
					'dataset' => [
						'toolbar-collapsed-icon' => Buttons\Icon::INFO,
					],
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
			(new SettingsPanel('BICONNECTOR_SUPERSET_SETTINGS'))
			->addController(
				$this->getController(),
				new IconController('ICON_CONTROLLER')
			)
			->addSection($this->getFilterSection())
			->setAjaxData($ajaxData)
		;

		if (!DatasetSettings::isTypingLocked())
		{
			$settingsPanel->addSection($this->getDatasetSettingsSection());
		}

		if (BIConnector\Manager::isAdmin())
		{
			$settingsPanel->addSection($this->getDashboardGlobalSettingsSection());
		}

		if (SupersetInitializer::isSupersetExist())
		{
			$settingsPanel->addSection($this->getClearCacheSection());

			$user = CurrentUser::get();

			if (KeyManager::canManageKey($user))
			{
				$settingsPanel->addSection($this->getSupersetKeySection());
			}
		}

		$this->arResult['SETTINGS_PANEL'] = $settingsPanel;
	}

	private function getController(): EntityEditorController
	{
		return new SettingsComponentController('SETTING_COMPONENT_CONTROLLER');
	}

	private function checkAccess(): Result
	{
		$result = new Result();

		if (!Feature::isBuilderEnabled())
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_SETTINGS_FEATURE_UNAVAILABLE')));

			return $result;
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_MSGVER_1')));

			return $result;
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_ACCESS))
		{
			$result->addError(new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_NO_RIGHTS_SETTINGS')));

			return $result;
		}

		return $result;
	}

	private function getFilterSection(): EntityEditorSection
	{
		$dateFilterSection = new EntityEditorSection(
			name: 'DEFAULT_RANGE_SETTINGS',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_SETTINGS_RANGE_FILTER_SECTION'),
		);
		$dateFilterSection->setIconClass('--calendar-1');
		$dateFilterSection->addField(new PeriodFilterField('DASHBOARD_FILTER'));

		return $dateFilterSection;
	}

	private function getClearCacheSection(): EntityEditorSection
	{
		return (new EntityEditorSection(
			name: 'CLEAR_CACHE_SECTION',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_NEW_DASHBOARD_CLEAR_CACHE_SECTION'),
		))
			->setIconClass('--refresh-5')
			->addField(new ClearCacheField('CLEAR_CACHE'))
		;
	}

	private function getDatasetSettingsSection(): EntityEditorSection
	{
		return (new EntityEditorSection(
			name: 'DATASET_SETTINGS_SECTION',
			title: Loc::getMessage('BICONNECTOR_SUPERSET_SETTINGS_DATASET_SECTION'),
		))
			->setIconClass('--table')
			->addField(new DatasetTypingField(DatasetTypingField::FIELD_NAME))
		;
	}

	private function getSupersetKeySection(): EntityEditorSection
	{
		return
			(new EntityEditorSection(
				name: 'KEY_INFO',
				title: Loc::getMessage('BICONNECTOR_SUPERSET_SETTINGS_KEY_INFO_SECTION'),
			))
				->setIconClass('--key')
				->addField(new KeyInfoField('KEY_VALUE'))
		;
	}

	private function getDashboardGlobalSettingsSection(): EntityEditorSection
	{
		$settingsUrl = '';
		if (Loader::includeModule('intranet'))
		{
			$settingsUrl = Portal::getInstance()->getSettings()->getSettingsUrl()
				. '?page=configuration&option=settings-configuration-section-biconnector';
		}

		return
			(new EntityEditorSection(
				name: 'DASHBOARD_GLOBAL_SETTINGS',
				title: Loc::getMessage('BICONNECTOR_SUPERSET_SETTINGS_DASHBOARD_GLOBAL_SETTINGS'),
			))
			->setIconClass('--o-earth')
			->addField(new DashboardLanguageField(DashboardLanguageField::FIELD_NAME))
			->addField(new TimeZoneField(TimeZoneField::FIELD_NAME))
			->addField(new GlobalSettingsButtonField(
				GlobalSettingsButtonField::FIELD_NAME,
				$settingsUrl
			))
		;
	}

	private function getTitle(): string
	{
		return Loc::getMessage('BICONNECTOR_SUPERSET_SETTINGS_TITLE');
	}

	public function getDashboardLanguageAction(): ?array
	{
		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		return [
			'currentLanguage' => CultureFormatter::getLanguage(),
		];
	}

	public function getTimeZoneAction(): ?array
	{
		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		$timezoneList = \CTimeZone::GetZones();

		return [
			'currentTimeZone' => $timezoneList[DataTimezone::getTimezone()] ?? '',
		];
	}

	public function saveAction(array $data): ?array
	{
		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		$startTime = null;
		$endTime = null;

		if ($data['FILTER_PERIOD'] === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
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
		}

		$period = EmbeddedFilter\DateTime::getDefaultPeriod();
		$innerPeriod = $data['FILTER_PERIOD'] ?? '';
		if (is_string($innerPeriod) && EmbeddedFilter\DateTime::isAvailablePeriod($innerPeriod))
		{
			$period = $innerPeriod;
		}

		$wasTypingEnabled = DatasetSettings::isTypingEnabled();
		$newTypingValue = $data[DatasetTypingField::FIELD_NAME] ?? null;
		$isTypingChanging =
			($newTypingValue === 'Y' || $newTypingValue === 'N')
			&& $wasTypingEnabled !== ($newTypingValue === 'Y')
			&& !DatasetSettings::isTypingLocked()
		;

		if ($isTypingChanging)
		{
			$clearResult = CacheManager::getInstance()->clear();
			if (!$clearResult->isSuccess())
			{
				$this->errorCollection->add($clearResult->getErrors());

				return null;
			}
		}

		$isTypingEnabled = DatasetSettings::setTypingOption($newTypingValue);

		Option::set('biconnector', EmbeddedFilter\DateTime::CONFIG_PERIOD_OPTION_NAME, $period);
		if ($startTime !== null)
		{
			Option::set('biconnector', EmbeddedFilter\DateTime::CONFIG_DATE_START_OPTION_NAME, $startTime->toString());
		}
		else
		{
			Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_START_OPTION_NAME]);
		}

		if ($endTime !== null)
		{
			Option::set('biconnector', EmbeddedFilter\DateTime::CONFIG_DATE_END_OPTION_NAME, $endTime->toString());
		}
		else
		{
			Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_END_OPTION_NAME]);
		}

		return [
			'FILTER_PERIOD' => $period,
			'DATE_FILTER_START' => $startTime,
			'DATE_FILTER_END' => $endTime,
			DatasetTypingField::FIELD_NAME => $isTypingEnabled,
			'INCLUDE_LAST_FILTER_DATE' => 'Y',
		];
	}

	/**
	 * @return string|null
	 */
	public function changeBiTokenAction(): ?string
	{
		$user = CurrentUser::get();
		if (!KeyManager::canManageKey($user))
		{
			return null;
		}

		$activeKeys = KeyTable::getList([
				'select' => [
					'ID',
				],
				'filter' => [
					'=SERVICE_ID' => ApacheSuperset::getServiceId(),
					'=ACTIVE' => 'Y',
					'=APP_ID' => false,
				],
			])
			->fetchCollection()
		;

		$result = KeyManager::createAccessKey($user);
		if (!$result->isSuccess())
		{
			return null;
		}

		$accessKey = $result->getData()['ACCESS_KEY'] ?? null;
		if (empty($accessKey))
		{
			return null;
		}

		$proxyIntegrator = Integrator::getInstance();
		$response = $proxyIntegrator->changeBiconnectorToken($accessKey);

		if ($response->hasErrors())
		{
			KeyManager::deleteKey($accessKey);

			return null;
		}

		$proxyIntegrator->refreshDomainConnection();

		if (!$activeKeys->isEmpty())
		{
			foreach ($activeKeys as $key)
			{
				$key->delete();
			}
		}
		else
		{
			Option::delete('biconnector', ['name' => KeyManager::SUPERSET_KEY_OPTION_NAME]);
		}

		return $accessKey;
	}
}
