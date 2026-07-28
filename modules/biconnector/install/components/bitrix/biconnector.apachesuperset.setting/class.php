<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\KeyTable;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\Intranet\Portal;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;

Loader::includeModule('biconnector');

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
				$this->arResult['errorMessages'][] = $message;
			}

			$this->includeComponentTemplate();

			return;
		}

		$this->arResult['title'] = $this->getTitle();
		$this->arResult['componentName'] = $this->getName();
		$this->arResult['signedParameters'] = $this->getSignedParameters();
		$this->arResult['cardsData'] = $this->getCardsData();
		$this->arResult['collapsedState'] = $this->getCollapsedState();

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

	private function getCardsData(): array
	{
		$cards = [];

		$cards['periodFilter'] = $this->getPeriodFilterData();

		if (!DatasetSettings::isTypingLocked())
		{
			$cards['datasetTyping'] = [
				'enabled' => DatasetSettings::isTypingEnabled(),
				'locked' => false,
			];
		}

		if (BIConnector\Manager::isAdmin())
		{
			$cards['languageTimezone'] = $this->getLanguageTimezoneData();
		}

		if (SupersetInitializer::isSupersetExist())
		{
			$cards['clearCache'] = $this->getClearCacheData();

			$user = CurrentUser::get();
			if (KeyManager::canManageKey($user))
			{
				$cards['encryptionKey'] = [
					'key' => KeyManager::getAccessKey(),
				];
			}
		}

		return $cards;
	}

	private function getPeriodFilterData(): array
	{
		$periods = [
			EmbeddedFilter\DateTime::PERIOD_LAST_7,
			EmbeddedFilter\DateTime::PERIOD_LAST_30,
			EmbeddedFilter\DateTime::PERIOD_LAST_90,
			EmbeddedFilter\DateTime::PERIOD_LAST_180,
			EmbeddedFilter\DateTime::PERIOD_LAST_365,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_WEEK,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_MONTH,
			EmbeddedFilter\DateTime::PERIOD_CURRENT_YEAR,
			EmbeddedFilter\DateTime::PERIOD_RANGE,
		];

		$items = [];
		foreach ($periods as $period)
		{
			$items[] = [
				'name' => EmbeddedFilter\DateTime::getPeriodName($period),
				'value' => $period,
			];
		}

		return [
			'items' => $items,
			'currentPeriod' => EmbeddedFilter\DateTime::getDefaultPeriod(),
			'dateStart' => EmbeddedFilter\DateTime::getDefaultDateStart()->toString(),
			'dateEnd' => EmbeddedFilter\DateTime::getDefaultDateEnd()->toString(),
		];
	}

	private function getLanguageTimezoneData(): array
	{
		$settingsUrl = '';
		if (Loader::includeModule('intranet'))
		{
			$settingsUrl = Portal::getInstance()->getSettings()->getSettingsUrl()
				. '?page=configuration&option=settings-configuration-section-biconnector';
		}

		$timezoneList = \CTimeZone::GetZones();

		return [
			'currentLanguage' => CultureFormatter::getLanguage(),
			'currentTimeZone' => $timezoneList[DataTimezone::getTimezone()] ?? '',
			'settingsUrl' => $settingsUrl,
		];
	}

	private function getCollapsedState(): array
	{
		$stored = \CUserOptions::GetOption('biconnector', 'settings_panel_collapsed', []);
		if (!is_array($stored))
		{
			return [];
		}

		$state = [];
		foreach ($stored as $cardId => $value)
		{
			$state[(string)$cardId] = ($value === 'Y');
		}

		return $state;
	}

	private function getClearCacheData(): array
	{
		$cacheManager = CacheManager::getInstance();
		$canClearCache = $cacheManager->canClearCache();

		return [
			'canClearCache' => $canClearCache,
			'clearCacheTimeout' => !$canClearCache ? $cacheManager->getNextClearTimeout() : null,
		];
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

	public function savePeriodFilterAction(array $data): ?array
	{
		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		$startTime = null;
		$endTime = null;
		$filterPeriod = $data['FILTER_PERIOD'] ?? '';

		if ($filterPeriod === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			try
			{
				$startTime = new Date($data['DATE_FILTER_START']);
				$endTime = new Date($data['DATE_FILTER_END']);
			}
			catch (\Bitrix\Main\ObjectException)
			{
				$this->errorCollection->setError(
					new Error(Loc::getMessage('BICONNECTOR_SUPERSET_ACTION_SETTINGS_SAVE_ERROR_INVALID_RANGE'))
				);

				return null;
			}
		}

		$period = EmbeddedFilter\DateTime::getDefaultPeriod();
		if (is_string($filterPeriod) && EmbeddedFilter\DateTime::isAvailablePeriod($filterPeriod))
		{
			$period = $filterPeriod;
		}

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
		];
	}

	public function saveDatasetTypingAction(string $newTypingValue): ?array
	{
		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		$wasTypingEnabled = DatasetSettings::isTypingEnabled();
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
		return [
			'enabled' => $isTypingEnabled,
		];
	}

	/**
	 * @deprecated Use savePeriodFilterAction + saveDatasetTypingAction instead
	 */
	public function saveAction(array $data): ?array
	{
		$checkingResult = $this->checkAccess();
		if (!$checkingResult->isSuccess())
		{
			$this->errorCollection->add($checkingResult->getErrors());

			return null;
		}

		$periodResult = $this->savePeriodFilterAction($data);
		if ($periodResult === null)
		{
			return null;
		}

		$isTypingEnabled = DatasetSettings::setTypingOption($data['DATASET_TYPING_ENABLED'] ?? null);

		return array_merge($periodResult, [
			'DATASET_TYPING_ENABLED' => $isTypingEnabled,
			'INCLUDE_LAST_FILTER_DATE' => 'Y',
		]);
	}

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

		$proxyIntegrator = IntegratorFactory::getInstance();
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
