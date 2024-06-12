<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\ProxyIntegrator;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Superset\ActionFilter\ProxyAuth;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\AppTable;

final class SupersetInitializer
{
	public const SUPERSET_STATUS_READY = 'READY';
	public const SUPERSET_STATUS_LOAD = 'LOAD';
	public const SUPERSET_STATUS_FROZEN = 'FROZEN';
	public const SUPERSET_STATUS_DISABLED = 'DISABLED';
	public const SUPERSET_STATUS_DOESNT_EXISTS = 'DOESNT_EXISTS'; // If portal startup superset first time
	public const SUPERSET_STATUS_DELETED_BY_CLIENT = 'DELETED_BY_CLIENT';

	public const FREEZE_REASON_TARIFF = 'TARIFF';

	private const LAST_STARTUP_ATTEMPT_OPTION = 'last_superset_startup_attempt';
	public const ERROR_DELETE_INSTANCE_OPTION = 'error_superset_delete_instance';

	/**
	 * @return string current superset status
	 */
	public static function startupSuperset(): string
	{
		$status = self::getSupersetStatus();
		$canSendAnotherRequest = ($status === self::SUPERSET_STATUS_LOAD && self::needCheckSupersetStatus());
		if (!self::isSupersetExist() || $canSendAnotherRequest)
		{
			SupersetInitializerLogger::logInfo('Portal make superset startup', ['current_status' => $status]);
			self::fixLastStartupAttempt();
			self::startSupersetInitialize($status !== self::SUPERSET_STATUS_LOAD);

			if ($status !== self::SUPERSET_STATUS_LOAD)
			{
				$status = self::SUPERSET_STATUS_LOAD;
				self::setSupersetStatus($status);
			}
		}

		return $status;
	}

	public static function fixLastStartupAttempt(): void
	{
		Option::set('biconnector', self::LAST_STARTUP_ATTEMPT_OPTION, (new DateTime())->getTimestamp());
	}

	private static function needCheckSupersetStatus(): bool
	{
		$lastCheck = (int)Option::get('biconnector', self::LAST_STARTUP_ATTEMPT_OPTION, 0);

		if ($lastCheck <= 0)
		{
			return true;
		}

		$lastCheckTime = DateTime::createFromTimestamp($lastCheck)->add('10 minutes');

		return (new DateTime()) >= $lastCheckTime; // check again after 10 minutes
	}

	public static function createSuperset(): string
	{
		$status = self::getSupersetStatus();
		if ($status !== self::SUPERSET_STATUS_DOESNT_EXISTS && $status !== self::SUPERSET_STATUS_LOAD)
		{
			return $status;
		}

		return self::startupSuperset();
	}

	private static function startSupersetInitialize($firstRequest = true): void
	{
		self::preloadSystemDashboards();
		\Bitrix\Main\Application::getInstance()->addBackgroundJob(fn() => self::makeSupersetCreateRequest($firstRequest));
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

		$logParams = [];
		if (!empty($supersetAddress))
		{
			$logParams['superset_address'] = $supersetAddress;
		}
		SupersetInitializerLogger::logInfo('Superset successfully started', $logParams);

		self::onSupersetCreated();
	}

	public static function freezeSuperset(array $params = []): void
	{
		$proxyIntegrator = ProxyIntegrator::getInstance();
		$proxyIntegrator->freezeSuperset($params);
	}

	public static function unfreezeSuperset(array $params = []): void
	{
		$proxyIntegrator = ProxyIntegrator::getInstance();
		$proxyIntegrator->unfreezeSuperset($params);
	}

	public static function setSupersetUnfreezed(): void
	{
		self::setSupersetStatus(self::SUPERSET_STATUS_READY);
		DashboardManager::notifySupersetUnfreeze();
	}

	public static function onSupersetCreated(): void
	{
		self::installInitialDashboards();
	}

	public static function setSupersetStatus(string $status): void
	{
		Option::set('biconnector', 'superset_status', $status);
	}

	public static function getSupersetStatus(): string
	{
		return Option::get('biconnector', 'superset_status', self::SUPERSET_STATUS_DOESNT_EXISTS);
	}

	private static function makeSupersetCreateRequest(bool $firstRequest = true): int
	{
		$proxyIntegrator = ProxyIntegrator::getInstance();
		if (!$firstRequest)
		{
			$proxyIntegrator->skipRequireFields();
		}

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
			return IntegratorResponse::STATUS_NO_ACCESS;
		}

		$response = $proxyIntegrator->startSuperset($accessKey);
		if ($response->getStatus() === IntegratorResponse::STATUS_CREATED)
		{
			self::enableSuperset($response->getData()['superset_address'] ?? '');

			return $response->getStatus();
		}

		if (!$response->hasErrors())
		{
			Option::set('biconnector', ProxyAuth::SUPERSET_PROXY_TOKEN_OPTION, $response->getData()['token']);
		}
		else
		{
			Option::delete('biconnector', ['name' => self::LAST_STARTUP_ATTEMPT_OPTION]);
			self::onUnsuccessfulSupersetStartup(...$response->getErrors());
		}

		return $response->getStatus();
	}

	private static function installInitialDashboards(): Result
	{
		return MarketDashboardManager::getInstance()->installInitialDashboards();
	}

	public static function isSupersetActive(): bool
	{
		$activeStatuses = [
			self::SUPERSET_STATUS_READY,
			self::SUPERSET_STATUS_FROZEN,
		];

		return in_array(self::getSupersetStatus(), $activeStatuses);
	}

	public static function isSupersetExist(): bool
	{
		$status = self::getSupersetStatus();

		return
			$status !== self::SUPERSET_STATUS_DOESNT_EXISTS
			&& $status !== self::SUPERSET_STATUS_DISABLED
			&& $status !== self::SUPERSET_STATUS_DELETED_BY_CLIENT
		;
	}

	public static function isSupersetLoad(): bool
	{
		$possibleLoadStatus = [
			self::SUPERSET_STATUS_LOAD,
			self::SUPERSET_STATUS_FROZEN,
		];

		return in_array(self::getSupersetStatus(), $possibleLoadStatus, true);
	}

	public static function isSupersetFrozen(): bool
	{
		return self::getSupersetStatus() === self::SUPERSET_STATUS_FROZEN;
	}

	public static function onUnsuccessfulSupersetStartup(Error ...$errors): void
	{
		if (!empty($errors))
		{
			SupersetInitializerLogger::logErrors($errors);
		}
		else
		{
			SupersetInitializerLogger::logErrors([new Error('undefined error while startup superset')]);
		}

		$marketManager = MarketDashboardManager::getInstance();
		$systemDashboards = $marketManager->getSystemDashboardApps();

		$existingDashboardInfoList = SupersetDashboardTable::getList([
			'select' => ['ID', 'APP_ID', 'STATUS'],
			'filter' => [
				'=APP_ID' => array_column($systemDashboards, 'CODE'),
				'=STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_LOAD,
			],
		])->fetchAll();

		if (empty($existingDashboardInfoList))
		{
			self::setSupersetStatus(self::SUPERSET_STATUS_DOESNT_EXISTS);
			return;
		}

		self::setSupersetStatus(self::SUPERSET_STATUS_DISABLED);

		SupersetDashboardTable::updateMulti(array_column($existingDashboardInfoList, 'ID'), [
			'STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_FAILED,
		]);

		$notifyList = [];
		foreach ($existingDashboardInfoList as $dashboardInfo)
		{
			$notifyList[] = [
				'id' => $dashboardInfo['ID'],
				'status' => SupersetDashboardTable::DASHBOARD_STATUS_FAILED,
			];
		}

		DashboardManager::notifyBatchDashboardStatus($notifyList);
	}

	public static function onBitrix24LicenseChange(): void
	{
		if (self::getSupersetStatus() === self::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			return;
		}

		if (self::getSupersetStatus() === self::SUPERSET_STATUS_DELETED_BY_CLIENT)
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

		$response = ProxyIntegrator::getInstance()->refreshDomainConnection();

		if (!$response->hasErrors() && $response->getStatus() === IntegratorResponse::STATUS_OK)
		{
			return null;
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
		$response = ProxyIntegrator::getInstance()->deleteSuperset();
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

			$dashboard->delete();
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
