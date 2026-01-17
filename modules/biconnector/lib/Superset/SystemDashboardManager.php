<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Logger\MarketDashboardLogger;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\Marketplace\Transport;

final class SystemDashboardManager
{
	/** @deprecated Will be removed in future updates. */
	public const SYSTEM_DASHBOARD_APP_ID_DEALS = 'deals';
	/** @deprecated Will be removed in future updates. */
	public const SYSTEM_DASHBOARD_APP_ID_LEADS = 'leads';
	/** @deprecated Will be removed in future updates. */
	public const SYSTEM_DASHBOARD_APP_ID_SALES = 'sales';
	/** @deprecated Will be removed in future updates. */
	public const SYSTEM_DASHBOARD_APP_ID_SALES_STRUCT = 'sales_struct';
	/** @deprecated Will be removed in future updates. */
	public const SYSTEM_DASHBOARD_APP_ID_TELEPHONY = 'telephony';

	private const SYSTEM_DASHBOARDS_MARKET_LIST_CACHE_KEY = 'biconnector_superset_dashboard_list_market_json_endpoint';

	public const SYSTEM_VENDOR_MAIN = 'bitrix';

	private const SYSTEM_VENDOR_ALAIO = 'alaio';

	public const SYSTEM_DASHBOARDS_DELETED_CODES_OPTION = 'deleted_system_dashboard_codes';

	private const SYSTEM_DASHBOARDS_TAG = 'bi_system_dashboard';

	private const SYSTEM_DASHBOARD_GROUP_CRM_TAG = ScopeService::BIC_SCOPE_CRM;
	private const SYSTEM_DASHBOARD_GROUP_SHOP_TAG = ScopeService::BIC_SCOPE_SHOP;
	private const SYSTEM_DASHBOARD_GROUP_PROFILE_TAG = ScopeService::BIC_SCOPE_PROFILE;
	private const SYSTEM_DASHBOARD_GROUP_BIZPROC_TAG = ScopeService::BIC_SCOPE_BIZPROC;
	private const SYSTEM_DASHBOARD_GROUP_TASKS_TAG = ScopeService::BIC_SCOPE_TASKS;

	/** @deprecated Will be removed in future updates. */
	public const OPTION_NEW_DASHBOARD_NOTIFICATION_LIST = 'new_dashboard_notification_list';

	/** @deprecated Will be removed in future updates. */
	private const RU_ZONE = 'ru';
	/** @deprecated Will be removed in future updates. */
	private const EN_ZONE = 'en';
	/** @deprecated Will be removed in future updates. */
	private const KZ_ZONE = 'kz';
	/** @deprecated Will be removed in future updates. */
	private const BY_ZONE = 'by';

	/** @deprecated Will be removed in future updates. */
	public static function resolveMarketAppId(string $appId): string
	{
		return '';
	}

	/** @deprecated Will be removed in future updates. */
	public static function getDashboardTitleByAppId(string $appId): string
	{
		return '';
	}

	/** @deprecated Will be removed in future updates. */
	private static function getDashboardLanguageCode(): string
	{
		return self::EN_ZONE;
	}

	/** @deprecated Will be removed in future updates. */
	public static function getNewDashboardNotificationUserIds(): array
	{
		return [];
	}

	/** @deprecated Will be removed in future updates. */
	public static function notifyUserDashboardModification(SupersetDashboard $dashboard, bool $isModification): void
	{
		return;
	}

	/**
	 * Adds agent to set admin as dashboard's owner if the previous owner was fired.
	 * @param $fields array User fields ACTIVE (Y/N) and ID.
	 *
	 * @return void
	 */
	public static function onAfterUserUpdateHandler(array $fields): void
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			return;
		}

		if (!isset($fields['ACTIVE']))
		{
			return;
		}

		if ($fields['ACTIVE'] === 'N')
		{
			$userId = (int)($fields['ID'] ?? 0);
			if ($userId)
			{
				\CAgent::addAgent(
					"\\Bitrix\\BIConnector\\Integration\\Superset\\Agent::setDefaultOwnerForDashboards({$userId});",
					'biconnector',
					'N',
					300,
					'',
					'Y',
					convertTimeStamp(time() + \CTimeZone::getOffset() + 300, 'FULL')
				);
			}
		}
	}

	public static function getSystemApps(): array
	{
		if (
			!Loader::includeModule('rest')
			|| !class_exists('Bitrix\Rest\Marketplace\Transport')
		)
		{
			return [];
		}

		$managedCache = Application::getInstance()->getManagedCache();
		$cacheId = self::SYSTEM_DASHBOARDS_MARKET_LIST_CACHE_KEY;

		if ($managedCache->read(86400, $cacheId))
		{
			return $managedCache->get($cacheId);
		}

		$apps = Transport::instance()->getDictionary(
			Transport::DICTIONARY_BI_BUILDER_SYSTEM_DASHBOARDS,
		);

		if (!is_array($apps))
		{
			return [];
		}

		$result = self::filterSystemApps($apps);
		$managedCache->set($cacheId, $result);

		return $result;
	}

	private static function filterSystemApps(array $marketApps): array
	{
		$systemApps = [];
		foreach ($marketApps as $app)
		{
			if (MarketDashboardManager::isSystemAppByAppCode($app['code']))
			{
				$systemApps[$app['code']] = $app;
			}
		}

		return $systemApps;
	}

	/**
	 * Once a day (by agent) gets all system dashboards from the market and adds rows to dashboard table
	 * or changes dashboard name if name was changed in market.
	 * Dashboards manually deleted by an admin are ignored and will not be re-added.
	 *
	 * @return void
	 */
	public static function actualizeSystemDashboards(): void
	{
		// Existing groups are required for correct saving dashboards.
		if (
			!Feature::isCheckPermissionsByGroup()
			|| !RoleTable::getRow([])
			|| !SupersetDashboardGroupTable::getRow([])
		)
		{
			MarketDashboardLogger::logInfo('actualizeSystemDashboards: no groups, break', [
				'group_option_status' => Feature::isCheckPermissionsByGroup() ? 'Y' : 'N',
				'count_system_groups' => SupersetDashboardGroupTable::getCount([
					'=TYPE' => SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM,
				]),
			]);

			return;
		}

		$apps = self::getSystemApps();

		$appCodes = array_keys($apps);
		$oldVendorCodes = [];
		foreach ($appCodes as $appCode)
		{
			$oldVendorCode = self::mapExistingAppCode($appCode);
			if ($oldVendorCode === $appCode)
			{
				continue;
			}

			$oldVendorCodes[$oldVendorCode] = $appCode;
		}

		$dashboardRows = SupersetDashboardTable::getList([
			'select' => ['ID', 'APP_ID', 'TITLE', 'STATUS', 'TYPE'],
			'filter' => [
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
			],
		])
			->fetchCollection()
		;

		$deletedDashboards = self::getDeletedSystemDashboard();
		$notFoundAppCodes = array_diff($appCodes, $dashboardRows->getAppIdList(), $deletedDashboards, $oldVendorCodes);
		$notFoundApps = array_intersect_key($apps, array_flip($notFoundAppCodes));
		foreach ($notFoundApps as $notFoundApp)
		{
			$dashboard = SupersetDashboardTable::createObject();
			$dashboard
				->setAppId($notFoundApp['code'])
				->setTitle(self::getDashboardNameFromMarket($notFoundApp['name']))
				->setType(SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
				->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
				->save()
			;
			$scopes = $notFoundApp['scope'] ?? [];
			$groupCode = $notFoundApp['groupCode'] ?? MarketDashboardManager::getDefaultDashboardGroupScope();
			MarketDashboardManager::getInstance()->applyDashboardSettings($dashboard, [
				'scope' => $scopes,
				'groupCode' => $groupCode,
			]);
		}

		foreach ($dashboardRows as $dashboardRow)
		{
			if ($dashboardRow->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
			{
				$appData = $apps[$oldVendorCodes[$dashboardRow->getAppId()]] //hack for apps from `alaio` vendor
					?? $apps[$dashboardRow->getAppId()]
					?? null
				;
				if ($appData)
				{
					$dashboardRow->setAppId($appData['code']);
					$name = self::getDashboardNameFromMarket($appData['name']);
					if ($name !== $dashboardRow->getTitle())
					{
						$dashboardRow->setTitle($name);
					}
					$scopes = $appData['scope'] ?? [];
					$groupCode = $appData['groupCode'] ?? MarketDashboardManager::getDefaultDashboardGroupScope();
					MarketDashboardManager::getInstance()->applyDashboardSettings($dashboardRow, [
						'scope' => $scopes,
						'groupCode' => $groupCode,
					]);

					$dashboardRow->save();
				}
			}
		}
	}

	public static function saveDeletedSystemDashboard(string $appCode): void
	{
		if (!self::canDeleteSystemDashboard())
		{
			return;
		}

		$deletedDashboards = self::getDeletedSystemDashboard();

		$deletedDashboards[] = $appCode;
		$jsonString = Json::encode(array_unique($deletedDashboards));
		Option::set(
			'biconnector',
			self::SYSTEM_DASHBOARDS_DELETED_CODES_OPTION,
			$jsonString
		);
	}

	private static function getDeletedSystemDashboard(): array
	{
		if (!self::canDeleteSystemDashboard())
		{
			return [];
		}

		try
		{
			return Json::decode(Option::get('biconnector', self::SYSTEM_DASHBOARDS_DELETED_CODES_OPTION, '[]'));
		}
		catch (ArgumentException $e)
		{
			return [];
		}
	}

	public static function getAdditionalSystemVendors(): array
	{
		return [
			self::SYSTEM_VENDOR_ALAIO,
		];
	}

	public static function tryToGetAdditionalSystemVendorByAppCode(string $appCode): null|string
	{
		$vendors = self::getAdditionalSystemVendors();
		$currentVendor = substr($appCode, 0, strpos($appCode, '.'));
		$index = array_search($currentVendor, $vendors);

		return is_int($index) ? $vendors[$index] : null;
	}

	/**
	 * Returns the existing app code used for system dashboards when vendor prefixes differ.
	 *
	 * @param string $appCode The app code of the dashboard being installed.
	 * @param string $vendorCode The vendor code extracted from the app code.
	 * @return string The existing app code with "bitrix" prefix.
	 */
	public static function getExistingAppCode(string $appCode, string $vendorCode): string
	{
		return str_replace($vendorCode, self::SYSTEM_VENDOR_MAIN, $appCode);
	}

	/**
	 * Maps the installing app code to the existing one if it's a system dashboard installed by an alternative vendor (e.g., alaio).
	 * If it's not a system vendor (or the original Bitrix vendor), returns the provided app code unchanged.
	 *
	 * @param string $appCode The app code of the dashboard being installed.
	 * @return string The mapped (or original) app code to be used.
	 */
	public static function mapExistingAppCode(string $appCode): string
	{
		$vendor = self::tryToGetAdditionalSystemVendorByAppCode($appCode);
		if (!$vendor)
		{
			return $appCode;
		}

		$existingAppCode = self::getExistingAppCode($appCode, $vendor);

		$existingDashboard = SupersetDashboardTable::getList([
			'select' => ['ID', 'APP_ID', 'EXTERNAL_ID', 'STATUS'],
			'filter' => ['=APP_ID' => $existingAppCode],
			'limit' => 1,
		])
			->fetchObject()
		;

		if (!$existingDashboard)
		{
			return $appCode;
		}

		return $existingAppCode;
	}

	public static function canDeleteSystemDashboard(): bool
	{
		return Option::get('biconnector', 'allow_delete_system_dashboard', 'N') === 'Y';
	}

	private static function getDashboardNameFromMarket(array $name): string
	{
		static $portalRegion = null;
		static $portalRegionIsCis = null;

		if (is_null($portalRegion) && is_null($portalRegionIsCis))
		{
			$licence = Application::getInstance()->getLicense();
			$portalRegion = $licence->getRegion();
			$portalRegionIsCis = $licence->isCis();
		}

		if (isset($name[$portalRegion]))
		{
			return $name[$portalRegion];
		}

		return $portalRegionIsCis ? $name['ru'] : $name['en'];
	}
}
