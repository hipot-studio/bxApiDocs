<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\StaffTrack\Dictionary\CheckInGeoPermissionState;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\Service\NodeSettingsBatchService;
use Bitrix\StaffTrack\Internal\Integration\Mobile\TouristConsentMarkerReader;

/**
 * Whether an employee has not granted the permission to collect his auto check-ins while they are switched on for
 * him: the one rule behind the mark of his row in the presence list and the plaque over the timeline of his day, so
 * that the two cannot part.
 *
 * The auto check-ins are on for him when the region allows geo features at all and at least one department he is a
 * member of has its auto check-in switched on, as NodeSettingsBatchService answers it: a department he only manages
 * does not count. A setting nobody could read decides nothing, so it answers no. The permission is granted when the
 * stored mark says granted or when he keeps the marker of the consent the tracker of his device collects the routes
 * by ({@see TouristConsentMarkerReader}), whatever the mark says: turning down the popup about the auto check-ins
 * takes the marker away before the postponement is sent, and the popup about the check-in at the start of the day
 * leaves it, and the routes are collected on. Otherwise the permission is not granted, whether he ever saw the popup
 * or not: which scenarios the mark names and how many times he postponed it do not matter.
 *
 * The date is no part of the rule - which dates ask it is for the caller to decide. No rights are checked.
 */
class AutoCheckInConsentRule
{
	private NodeSettingsBatchService $nodeSettingsService;
	private TouristConsentMarkerReader $markerReader;

	public function __construct(
		?NodeSettingsBatchService $nodeSettingsService = null,
		?TouristConsentMarkerReader $markerReader = null,
	)
	{
		$this->nodeSettingsService = $nodeSettingsService ?? new NodeSettingsBatchService();
		$this->markerReader = $markerReader ?? new TouristConsentMarkerReader();
	}

	/**
	 * The rule for one employee, read lazily and the cheapest first: a granted mark answers before anything else is
	 * asked, the region next, then the marker - one reading of his options, mostly off the cache of main - and the
	 * departments last, by the two queries of the batch reading. A marker nobody could read answers no, as a setting
	 * nobody could read does: a plaque over a permission that may well be granted is worse than none.
	 *
	 * @param int $userId
	 * @param CheckInGeoPermissionState|null $state the state of the stored mark; null when there is none
	 * @return bool
	 */
	public function isWithheldForUser(int $userId, ?CheckInGeoPermissionState $state): bool
	{
		if ($userId <= 0 || self::isGranted($state) || !$this->isGeoFeaturesAvailableByRegion())
		{
			return false;
		}

		try
		{
			$hasMarker = $this->markerReader->hasMarker($userId);
		}
		catch (\Throwable)
		{
			return false;
		}

		if ($hasMarker)
		{
			return false;
		}

		try
		{
			$settings = $this->nodeSettingsService->isCheckInCollectedForUsers([$userId]);
		}
		catch (\Throwable)
		{
			$settings = [];
		}

		return self::isWithheld($settings[$userId] ?? [], $state);
	}

	/**
	 * The rule for the rows of a list, over the settings and the marks the caller has read of all of them at once.
	 * The markers are read by one query, and only of the rows they can still take the mark off: the ones the rule
	 * marks short of the marker. A marker nobody could read throws - the consent of the rows is not known then, and
	 * the caller answers for it.
	 *
	 * @param int[] $userIds
	 * @param array<int, array{trusted: bool, collects: bool}> $settings whether the departments of the employee
	 *     take check-ins automatically, as NodeSettingsBatchService::isCheckInCollectedForUsers() answers it
	 * @param array<int, CheckInGeoPermissionState|null> $states the state of the stored mark; null or no key when
	 *     there is none
	 * @return array<int, bool> a verdict for every employee asked about
	 */
	public function isWithheldForUsers(array $userIds, array $settings, array $states): array
	{
		$isAvailable = $this->isGeoFeaturesAvailableByRegion();

		$candidates = [];
		foreach ($userIds as $userId)
		{
			if ($isAvailable && self::isWithheld($settings[$userId] ?? [], $states[$userId] ?? null))
			{
				$candidates[$userId] = $userId;
			}
		}

		$markers = $candidates === [] ? [] : $this->markerReader->hasMarkers(array_values($candidates));

		$verdicts = [];
		foreach ($userIds as $userId)
		{
			$verdicts[$userId] = isset($candidates[$userId]) && !($markers[$userId] ?? false);
		}

		return $verdicts;
	}

	protected function isGeoFeaturesAvailableByRegion(): bool
	{
		return Feature::isGeoFeaturesAvailableByRegion();
	}

	/**
	 * @param array{trusted?: bool, collects?: bool} $setting
	 */
	private static function isWithheld(array $setting, ?CheckInGeoPermissionState $state): bool
	{
		return
			($setting['trusted'] ?? false)
			&& ($setting['collects'] ?? false)
			&& !self::isGranted($state)
		;
	}

	private static function isGranted(?CheckInGeoPermissionState $state): bool
	{
		return $state === CheckInGeoPermissionState::GRANTED;
	}
}
