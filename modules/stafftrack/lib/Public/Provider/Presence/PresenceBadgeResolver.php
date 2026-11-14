<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\StaffTrack\Internal\Integration\HumanResources\Service\NodeSettingsBatchService;
use Bitrix\StaffTrack\Internal\Service\CheckIn\AutoCheckInConsentRule;
use Bitrix\StaffTrack\Public\Provider\CheckInGeoPermissionProvider;

/**
 * The mark of a row: the department of the employee takes check-ins automatically and he has not granted the consent,
 * by the one rule the plaque over the timeline of his day stands on as well ({@see AutoCheckInConsentRule}).
 *
 * It reads the consent - the stored mark, and the marker the device of the employee collects the routes by -
 * rather than the check-ins of the date, so it does not depend on the date asked for or on the retention of
 * coordinates: which dates carry the mark is for the list to decide. An unread auto check-in setting leaves the
 * row unmarked, and a consent nobody could read throws.
 */
class PresenceBadgeResolver
{
	private CheckInGeoPermissionProvider $permissionProvider;
	private AutoCheckInConsentRule $consentRule;

	public function __construct(
		?CheckInGeoPermissionProvider $permissionProvider = null,
		?AutoCheckInConsentRule $consentRule = null,
	)
	{
		$this->permissionProvider = $permissionProvider ?? new CheckInGeoPermissionProvider();
		$this->consentRule = $consentRule ?? new AutoCheckInConsentRule();
	}

	/**
	 * The marks of the rows of a list, the auto check-in of their departments read for all of them at once - the
	 * door the list reads its marks by. A reading of the setting that threw leaves every row unmarked and the list
	 * answered (ERR-014); a consent nobody could read throws.
	 *
	 * @param int[] $userIds
	 * @param NodeSettingsBatchService $nodeSettingsService what the list reads the auto check-in of the departments by
	 * @return array<int, array{noConsent: bool}>
	 */
	public function resolveReadingSettings(array $userIds, NodeSettingsBatchService $nodeSettingsService): array
	{
		try
		{
			$settings = $nodeSettingsService->isCheckInCollectedForUsers($userIds);
		}
		catch (\Throwable)
		{
			$settings = [];
		}

		return $this->resolve($userIds, $settings);
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, array{trusted: bool, collects: bool}> $settings whether the departments of the
	 *     employee take check-ins automatically, as NodeSettingsBatchService::isCheckInCollectedForUsers() answers it
	 * @return array<int, array{noConsent: bool}>
	 */
	public function resolve(array $userIds, array $settings): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$states = [];
		foreach ($this->permissionProvider->getPermissions($userIds) as $userId => $permission)
		{
			$states[$userId] = $permission?->state;
		}

		$badges = [];
		foreach ($this->consentRule->isWithheldForUsers($userIds, $settings, $states) as $userId => $isWithheld)
		{
			$badges[$userId] = ['noConsent' => $isWithheld];
		}

		return $badges;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private static function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
