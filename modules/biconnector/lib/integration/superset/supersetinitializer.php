<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Superset\ActionFilter\ProxyAuth;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Rest\AppTable;

final class SupersetInitializer
{
	public const SUPERSET_STATUS_DOESNT_EXISTS = 'DOESNT_EXISTS';
	public const SUPERSET_STATUS_LOAD = 'LOAD';
	public const SUPERSET_STATUS_READY = 'READY';
	public const SUPERSET_STATUS_ERROR = 'ERROR';
	public const SUPERSET_STATUS_DELETED = 'DELETED';

	public const FREEZE_REASON_TARIFF = 'TARIFF';

	public const ERROR_DELETE_INSTANCE_OPTION = 'error_superset_delete_instance';

	/**
	 * Container for superset status. Used for tests mocking
	 *
	 * @var SupersetStatusOptionContainer
	 */
	private static SupersetStatusOptionContainer $statusContainer;

	/**
	 * @return string current superset status
	 */
	public static function startupSuperset(): string
	{
		SupersetInitializerLogger::logInfo('Portal make superset startup', ['current_status' => self::getSupersetStatus()]);

		$newStatus = self::startSupersetInitialize();
		self::setSupersetStatus($newStatus);

		return $newStatus;
	}

	public static function initializeOrCheckSupersetStatus(): string
	{
		$status = self::getSupersetStatus();

		$touchStatuses = [
			self::SUPERSET_STATUS_ERROR,
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_DOESNT_EXISTS,
		];

		if (!in_array($status, $touchStatuses))
		{
			return $status;
		}

		return self::startupSuperset();
	}

	private static function startSupersetInitialize(): string
	{
		self::preloadSystemDashboards();
		if (self::getSupersetStatus() !== self::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			$status = self::makeSupersetCreateRequest();
		}
		else
		{
			\Bitrix\Main\Application::getInstance()->addBackgroundJob(fn() => self::makeSupersetCreateRequest());
			$status = self::SUPERSET_STATUS_LOAD;
		}

		return $status;
	}

	private static function preloadSystemDashboards(): void
	{
		$marketManager = MarketDashboardManager::getInstance();
		$systemDashboards = $marketManager->getSystemDashboardApps();
		$existingDashboardInfoList = SupersetDashboardTable::getList([
			'select' => ['ID', 'APP_ID', 'STATUS'],
			'filter' => [
				'=APP_ID' => array_column($systemDashboards, 'CODE'),
			],
		])->fetchAll();

		$existingDashboardAppIds = array_column($existingDashboardInfoList, 'APP_ID');

		foreach ($systemDashboards as $systemDashboard)
		{
			if (!in_array($systemDashboard['CODE'], $existingDashboardAppIds))
			{
				self::preloadSystemDashboard($systemDashboard['CODE'], $systemDashboard['NAME']);
			}
		}

		if (count($existingDashboardInfoList) > 0)
		{
			$notifyList = [];
			foreach ($existingDashboardInfoList as $dashboardInfo)
			{
				if ($dashboardInfo['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_FAILED)
				{
					SupersetDashboardTable::update($dashboardInfo['ID'], [
						'STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_LOAD,
					]);
				}

				$notifyList[] = [
					'id' => $dashboardInfo['ID'],
					'status' => SupersetDashboardTable::DASHBOARD_STATUS_LOAD,
				];
			}

			DashboardManager::notifyBatchDashboardStatus($notifyList);
		}
	}

	private static function preloadSystemDashboard(string $appId, string $appTitle): void
	{
		SupersetDashboardTable::add([
			'TITLE' => $appTitle,
			'APP_ID' => $appId,
			'TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
			'STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_LOAD,
		]);
	}

	/**
	 * @param string $supersetAddress Address of enabled superset. Used for logs. Not required
	 * @return void
	 */
	public static function enableSuperset(string $supersetAddress = ''): void
	{
		if (self::getSupersetStatus() === self::SUPERSET_STATUS_READY)
		{
			return;
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_READY);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_READY);

		$logParams = [];
		if (!empty($supersetAddress))
		{
			$logParams['superset_address'] = $supersetAddress;
		}
		SupersetInitializerLogger::logInfo('Superset successfully started', $logParams);

		\Bitrix\Main\Application::getInstance()->addBackgroundJob(fn() => self::installInitialDashboards());
	}

	public static function freezeSuperset(array $params = []): void
	{
		$proxyIntegrator = Integrator::getInstance();
		$proxyIntegrator->freezeSuperset($params);
	}

	public static function unfreezeSuperset(array $params = []): void
	{
		$proxyIntegrator = Integrator::getInstance();
		$proxyIntegrator->unfreezeSuperset($params);
	}

	public static function setSupersetStatus(string $status): void
	{
		if (!isset(self::$statusContainer))
		{
			self::$statusContainer = new SupersetStatusOptionContainer();
		}

		self::$statusContainer->set($status);
	}

	public static function getSupersetStatus(): string
	{
		if (!isset(self::$statusContainer))
		{
			self::$statusContainer = new SupersetStatusOptionContainer();
		}

		return self::$statusContainer->get();
	}

	public static function setSupersetStatusContainer(SupersetStatusOptionContainer $container)
	{
		self::$statusContainer = $container;
	}

	private static function makeSupersetCreateRequest(): string
	{
		$proxyIntegrator = Integrator::getInstance();

		$user = \Bitrix\Main\Engine\CurrentUser::get();
		$accessKey = KeyManager::getAccessKey();
		if ($accessKey === null)
		{
			$createdResult = KeyManager::createAccessKey($user);
			if ($createdResult->isSuccess())
			{
				$accessKey = $createdResult->getData()['ACCESS_KEY'] ?? null;
			}
		}

		if ($accessKey === null)
		{
			return self::SUPERSET_STATUS_ERROR;
		}

		$response = $proxyIntegrator->startSuperset($accessKey);
		if ($response->getStatus() === IntegratorResponse::STATUS_CREATED)
		{
			self::enableSuperset($response->getData()['superset_address'] ?? '');

			return self::SUPERSET_STATUS_READY;
		}

		if (!$response->hasErrors())
		{
			Option::set('biconnector', ProxyAuth::SUPERSET_PROXY_TOKEN_OPTION, $response->getData()['token']);

			return self::SUPERSET_STATUS_LOAD;
		}

		self::onUnsuccessfulSupersetStartup(...$response->getErrors());

		return self::SUPERSET_STATUS_ERROR;
	}

	private static function installInitialDashboards(): Result
	{
		return MarketDashboardManager::getInstance()->installInitialDashboards();
	}

	public static function isSupersetReady(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_READY;
	}

	public static function isSupersetExist(): bool
	{
		$status = self::getSupersetStatus();

		return $status !== self::SUPERSET_STATUS_DOESNT_EXISTS && $status !== self::SUPERSET_STATUS_DELETED;
	}

	public static function isSupersetDeleted(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_DELETED;
	}

	public static function isSupersetLoad(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_LOAD;
	}

	public static function isSupersetDoesntWork(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_ERROR;
	}

	public static function onUnsuccessfulSupersetStartup(Error ...$errors): void
	{
		if (!empty($errors))
		{
			SupersetInitializerLogger::logErrors($errors, ['message' => 'error while startup superset']);
		}
		else
		{
			SupersetInitializerLogger::logErrors(
				[new Error('undefined error while startup superset')],
				['message' => 'error while startup superset']
			);
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_ERROR);
		DashboardManager::notifySupersetStatus(self::SUPERSET_STATUS_ERROR);
	}

	public static function onBitrix24LicenseChange(): void
	{
		if (self::getSupersetStatus() === self::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			return;
		}

		if (self::getSupersetStatus() === self::SUPERSET_STATUS_DELETED)
		{
			self::setSupersetStatus(self::SUPERSET_STATUS_DOESNT_EXISTS);

			return;
		}

		if (Loader::includeModule('bitrix24'))
		{
			$params = [
				'reason' => self::FREEZE_REASON_TARIFF,
			];

			if (Feature::isFeatureEnabledFor('bi_constructor', \CBitrix24::getLicenseType()))
			{
				self::unfreezeSuperset($params);
			}
			else
			{
				self::freezeSuperset($params);
			}
		}
	}

	public static function refreshSupersetDomainConnection(): ?string
	{
		if (!self::isSupersetExist())
		{
			return null;
		}

		if (
			Integrator::getInstance()->ping()
			&& self::getSupersetStatus() === self::SUPERSET_STATUS_READY
		)
		{
			$response = Integrator::getInstance()->refreshDomainConnection();

			if (!$response->hasErrors() && $response->getStatus() === IntegratorResponse::STATUS_OK)
			{
				return null;
			}
		}

		$className = __CLASS__;
		$agentName = "\\$className::refreshSupersetDomainConnection();";
		$agent = \CAgent::GetList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'biconnector',
				'NAME' => $agentName,
			]
		)
			->Fetch()
		;

		if (!$agent)
		{
			\CAgent::AddAgent(
				$agentName,
				'biconnector',
				'N',
				3600,
				'',
				'Y',
				\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 1800, 'FULL')
			);
		}

		return $agentName;
	}

	public static function deleteInstance(): Result
	{
		$result = new Result();
		$response = Integrator::getInstance()->deleteSuperset();
		if ($response->hasErrors())
		{
			$result->addErrors($response->getErrors());

			return $result;
		}

		return $result;
	}

	/**
	 * Clears all data abount BI Constructor - tables and market apps.
	 *
	 * @return void
	 */
	public static function clearSupersetData(): void
	{
		$dashboards = SupersetDashboardTable::getList(['select' => ['*', 'APP']])->fetchCollection();
		foreach ($dashboards as $dashboard)
		{
			$app = $dashboard->getApp();
			if ($app)
			{
				AppTable::uninstall($app->getCode());
				AppTable::update($app->getId(), ['ACTIVE' => 'N', 'INSTALLED' => 'N']);
			}

			$dashboardId = $dashboard->getId();
			$deleteResult = $dashboard->delete();
			if (!$deleteResult->isSuccess())
			{
				Logger::logErrors($deleteResult->getErrors(), ['clearSupersetData, deleting dashboard ' . $dashboardId]);
			}
		}

		$apps = AppTable::getList()->fetchCollection();
		foreach ($apps as $app)
		{
			if ($app->getCode() && MarketDashboardManager::isDatasetAppByAppCode($app->getCode()))
			{
				AppTable::uninstall($app->getCode());
				AppTable::update($app->getId(), ['ACTIVE' => 'N', 'INSTALLED' => 'N']);
			}
		}

		foreach (SupersetUserTable::getList()->fetchCollection() as $user)
		{
			$user->delete();
		}

		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_PERIOD_OPTION_NAME]);
		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_START_OPTION_NAME]);
		Option::delete('biconnector', ['name' => EmbeddedFilter\DateTime::CONFIG_DATE_END_OPTION_NAME]);
		Option::delete('biconnector', ['name' => SystemDashboardManager::OPTION_NEW_DASHBOARD_NOTIFICATION_LIST]);
		Option::delete('biconnector', ['name' => self::ERROR_DELETE_INSTANCE_OPTION]);

		// TODO Clear permission and tag tables
	}
}
