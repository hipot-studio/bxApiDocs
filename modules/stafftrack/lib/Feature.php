<?php

namespace Bitrix\StaffTrack;

use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\StaffTrack\Public\Feature\OfficesFlag;
use Bitrix\StaffTrack\Public\Services\DepartmentFeatureToggle;
use Bitrix\StaffTrack\Service\CheckIn\RegionAvailabilityService;

class Feature
{
	public const MODULE_ID = 'stafftrack';
	public const CHECK_IN_SETTINGS_KEY = 'feature_check_in_enabled_by_settings';
	public const CHECK_IN_GEO_ENABLED_KEY = 'feature_check_in_geo_enabled';
	public const CHECK_IN_START_KEY = 'feature_check_in_start_enabled';

	public const CHECK_IN_ROLLOUT_ENABLED_KEY = 'feature_check_in_rollout_enabled';
	public const CHECK_IN_ROLLOUT_DEPARTMENTS_KEY = 'feature_check_in_rollout_departments';

	public const CHECK_IN_DEPUTY_ACCESS_KEY = 'feature_check_in_deputy_access_enabled';

	public const CHECK_IN_V2_MESSAGE_KEY = 'feature_check_in_v2_message_enabled';

	public const OFFICE_PRESENCE_SIGNAL_KEY = 'feature_office_presence_signal_enabled';

	/**
	 * The tab of the check-in is opened from an item of the mobile menu, and that item carries the code of
	 * the component the rollout chooses. The menu is cached whole for thirty days and knows nothing about
	 * this option: none of the tags it registers belongs to this module, and Option::set publishes no event
	 * to hang on. Left alone, a portal switched to the new check-in would keep handing out the old one until
	 * the cache expired or somebody else's tag happened to clear it.
	 */
	private const MENU_LIST_CACHE_TAG = 'mobile_custom_menu';

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	public static function isCheckInEnabled(): bool
	{
		return Loader::includeModule('mobile')
			&& Loader::includeModule('mobileapp')
			&& Loader::includeModule('stafftrackmobile')
		;
	}

	/**
	 * @return bool
	 */
	public static function isGeoFeaturesAvailableByRegion(): bool
	{
		return (new RegionAvailabilityService())->isGeoFeaturesAvailableByRegion();
	}

	/**
	 * @return bool
	 */
	public static function isGeoRetentionLimited(): bool
	{
		return (new RegionAvailabilityService())->isGeoRetentionLimited();
	}

	public static function isGeoEncryptionAvailable(): bool
	{
		return CryptoField::cryptoAvailable();
	}

	/**
	 * @return bool
	 */
	public static function isCheckInEnabledBySettings(): bool
	{
		return Option::get(self::MODULE_ID, self::CHECK_IN_SETTINGS_KEY, 'Y') === 'Y';
	}

	/**
	 * The flag of the office directory is declared in the public zone of the module, and the code of a
	 * flag is its class name: asking about it from here keeps the question reachable to the private zone
	 * without moving the class the stands already keep the state of.
	 */
	public static function isOfficesFlagEnabled(): bool
	{
		return Config\Feature::isEnabled(OfficesFlag::class);
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInSettingOn(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_SETTINGS_KEY, 'Y');
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInSettingOff(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_SETTINGS_KEY, 'N');
	}

	/**
	 * @return bool
	 */
	public static function isCheckInGeoEnabled(): bool
	{
		$option = Option::get(self::MODULE_ID, self::CHECK_IN_GEO_ENABLED_KEY, 'default');

		if ($option === 'default')
		{
			$portalZone = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?? 'en';

			return in_array($portalZone, ['ru', 'by', 'kz', 'br', 'in'], true);
		}

		return $option === 'Y';
	}

	/**
	 * @return bool
	 */
	public static function isCheckInStartEnabled(): bool
	{
		$option = Option::get(self::MODULE_ID, self::CHECK_IN_START_KEY, 'N');

		return $option === 'Y';
	}

	/**
	 * @return bool
	 */
	public static function isWesternPortal(): bool
	{
		$portalZone = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?? 'en';

		return !in_array($portalZone, ['ru', 'by', 'kz', 'br', 'in'], true);
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInGeoOn(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_GEO_ENABLED_KEY, 'Y');
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInGeoOff(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_GEO_ENABLED_KEY, 'N');
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInStartOn(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_START_KEY, 'Y');
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInStartOff(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_START_KEY, 'N');
	}

	public static function isCheckInDeputyAccessEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::CHECK_IN_DEPUTY_ACCESS_KEY, 'N') === 'Y';
	}

	/**
	 * The kill switch of the presence signal of the mobile client: on unless a portal turned it off by
	 * hand, so it has no methods of its own to flip it.
	 */
	public static function isOfficePresenceSignalEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OFFICE_PRESENCE_SIGNAL_KEY, 'Y') === 'Y';
	}

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	public static function isCheckInV2MessageAvailable(): bool
	{
		return Option::get(self::MODULE_ID, self::CHECK_IN_V2_MESSAGE_KEY, 'N') === 'Y'
			&& Loader::includeModule('im')
			&& \Bitrix\Im\V2\Application\Features::isMessageBuilderAvailable()
		;
	}

	public static function isCheckInRolloutEnabled(int $userId): bool
	{
		return (new DepartmentFeatureToggle())->isEnabledForUser(
			userId: $userId,
			moduleId: self::MODULE_ID,
			companyOptionKey: self::CHECK_IN_ROLLOUT_ENABLED_KEY,
			deptsOptionKey: self::CHECK_IN_ROLLOUT_DEPARTMENTS_KEY,
		);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool>
	 */
	public static function filterCheckInRolloutEnabled(array $userIds): array
	{
		return (new DepartmentFeatureToggle())->filterEnabledForUsers(
			userIds: $userIds,
			moduleId: self::MODULE_ID,
			companyOptionKey: self::CHECK_IN_ROLLOUT_ENABLED_KEY,
			deptsOptionKey: self::CHECK_IN_ROLLOUT_DEPARTMENTS_KEY,
		);
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInRolloutOn(): void
	{
		(new DepartmentFeatureToggle())->enableForCompany(self::MODULE_ID, self::CHECK_IN_ROLLOUT_ENABLED_KEY);
		self::clearMenuListCache();
	}

	/**
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function turnCheckInRolloutOff(): void
	{
		(new DepartmentFeatureToggle())->disableForCompany(self::MODULE_ID, self::CHECK_IN_ROLLOUT_ENABLED_KEY);
		self::clearMenuListCache();
	}

	/**
	 * @param int[] $departmentIds
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function setCheckInRolloutDepartments(array $departmentIds): void
	{
		(new DepartmentFeatureToggle())->setEnabledDepartments(
			self::MODULE_ID,
			self::CHECK_IN_ROLLOUT_DEPARTMENTS_KEY,
			$departmentIds,
		);
		self::clearMenuListCache();
	}

	public static function isCheckInRolloutEnabledForCompany(): bool
	{
		return (new DepartmentFeatureToggle())->getCompanyEnabled(self::MODULE_ID, self::CHECK_IN_ROLLOUT_ENABLED_KEY);
	}

	/**
	 * @return int[]
	 */
	public static function getCheckInRolloutDepartments(): array
	{
		return (new DepartmentFeatureToggle())->getEnabledDepartments(
			self::MODULE_ID,
			self::CHECK_IN_ROLLOUT_DEPARTMENTS_KEY,
		);
	}

	/**
	 * Every menu of the portal at once, not the menu of one employee: the switch is portal-wide, and the
	 * departments it is narrowed to are read at build time by whoever asks. The tag is registered by
	 * Bitrix\Mobile\Menu\Service\MenuListCache for every user it caches.
	 */
	private static function clearMenuListCache(): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::MENU_LIST_CACHE_TAG);
	}
}
