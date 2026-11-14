<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Dictionary\CheckInGeoPermissionScenario;
use Bitrix\StaffTrack\Dictionary\CheckInGeoPermissionState;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Internal\Service\CheckIn\AutoCheckInConsentRule;
use Bitrix\StaffTrack\Model\OptionTable;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;

/**
 * Keeps the mark of the geo permission an employee left in the location permission popup. Which
 * scenarios the mark names is decided here and not by the caller: the mobile client caches the
 * settings for a day, so the composition it knows may already be wrong.
 *
 * No rights are checked. Reading a mark, telling whether the permission is withheld, and recording a
 * permission that was granted work by the user id they are given: the departments and the consent marker
 * of an arbitrary employee are read by his id. Writing a postponement still does not: it names the
 * scenarios switched on for the session user, the one the popup was shown to, so `registerPostponed()` is
 * meant for the employee who is making the request and nobody else - see the note on it.
 *
 * Whether the employee has postponed the popup too many times is decided here as well: the decision on
 * the limit is the only place the threshold lives, and the client gets the decision, never the count.
 */
class CheckInGeoPermissionProvider
{
	/**
	 * Postponements in a row after which the popup stops asking. Reaching it is not a state of the mark:
	 * the count goes on as before and a granted permission drops it, which is what lifts the limit. It
	 * does not weigh on whether the permission is withheld ({@see isPostponementInForce()}).
	 */
	public const POSTPONEMENT_LIMIT = 3;

	private const MARK_OPTIONS = [
		Option::CHECKIN_GEO_PERMISSION_STATE,
		Option::CHECKIN_GEO_DECLINED_SCENARIOS,
		Option::CHECKIN_GEO_DECLINED_COUNT,
		Option::CHECKIN_GEO_DECLINED_AT,
	];

	private OptionService $optionService;
	private OptionProvider $optionProvider;
	private CheckInSettingsProvider $settingsProvider;
	private AutoCheckInConsentRule $consentRule;

	public function __construct(
		?OptionService $optionService = null,
		?OptionProvider $optionProvider = null,
		?CheckInSettingsProvider $settingsProvider = null,
		?AutoCheckInConsentRule $consentRule = null,
	)
	{
		$this->optionService = $optionService ?? OptionService::getInstance();
		$this->optionProvider = $optionProvider ?? OptionProvider::getInstance();
		$this->settingsProvider = $settingsProvider ?? new CheckInSettingsProvider();
		$this->consentRule = $consentRule ?? new AutoCheckInConsentRule();
	}

	/**
	 * With no scenario switched on nobody asked the employee for the permission, so nothing is stored
	 * and an existing mark is left as it is.
	 *
	 * An identifier that names no employee is dropped, the same way the reading half drops it: the
	 * facade is public and does not inherit the authentication prefilter of the controller.
	 *
	 * Only for the employee making the request. The mark is written under the id given here, but the
	 * scenarios it names are read from the settings of the session user, so an id belonging to
	 * somebody else would store their mark with a composition taken from another department - and
	 * with no session at all the composition comes out empty and nothing is written without a word.
	 *
	 * @param int $userId the employee of the current session
	 * @return void
	 */
	public function registerPostponed(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$scenarios = $this->getEnabledScenarios();
		if ($scenarios === [])
		{
			return;
		}

		$count = $this->getPostponedCount($userId) + 1;

		$this->optionService->save(
			$userId,
			Option::CHECKIN_GEO_PERMISSION_STATE,
			CheckInGeoPermissionState::POSTPONED->value,
		);
		$this->optionService->save(
			$userId,
			Option::CHECKIN_GEO_DECLINED_SCENARIOS,
			CheckInGeoPermissionScenario::serialize($scenarios),
		);
		$this->optionService->save(
			$userId,
			Option::CHECKIN_GEO_DECLINED_COUNT,
			(string)$count,
		);
		$this->optionService->save(
			$userId,
			Option::CHECKIN_GEO_DECLINED_AT,
			(string)time(),
		);

		$this->optionProvider->invalidateCache($userId);
	}

	/**
	 * The state is stored and not dropped: an employee with no mark at all is one who never answered
	 * the popup, and telling the two apart is the whole point of the mark.
	 *
	 * An identifier that names no employee is dropped, the same way the reading half drops it: the
	 * facade is public and does not inherit the authentication prefilter of the controller.
	 *
	 * Unlike the postponement, this half is not gated by the region: the postponement is gated only
	 * as a consequence of naming scenarios, and no scenario is switched on where geo features are
	 * forbidden. What is recorded here is that the phone gave the permission, which is true of the
	 * device whatever the region allows the portal to collect, and the record is what stops the popup
	 * from asking again. Whether a forbidden region should refuse it too is a product question this
	 * facade leaves open.
	 *
	 * @param int $userId
	 * @return void
	 */
	public function registerGranted(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$this->optionService->save(
			$userId,
			Option::CHECKIN_GEO_PERMISSION_STATE,
			CheckInGeoPermissionState::GRANTED->value,
		);
		$this->optionService->delete($userId, Option::CHECKIN_GEO_DECLINED_SCENARIOS);
		$this->optionService->delete($userId, Option::CHECKIN_GEO_DECLINED_COUNT);
		$this->optionService->delete($userId, Option::CHECKIN_GEO_DECLINED_AT);

		$this->optionProvider->invalidateCache($userId);
	}

	/**
	 * The settings of the employee are taken as one snapshot and picked apart in memory: the provider
	 * keeps nothing in its cache for an employee with no settings at all, so a key by key read would
	 * ask the storage anew for every one of the four keys - and an employee with no mark is exactly the
	 * one this mark is about.
	 *
	 * An identifier that names no employee is dropped before the storage is asked, the same way both
	 * writing halves and the list read drop it: nobody owns such a mark, and a row stored under it by
	 * some other writer would answer for an employee who does not exist.
	 *
	 * @param int $userId
	 * @return CheckInGeoPermissionDto|null
	 */
	public function getPermission(int $userId): ?CheckInGeoPermissionDto
	{
		if ($userId <= 0)
		{
			return null;
		}

		$options = $this->optionProvider->getUserOptions($userId);

		$values = [];
		foreach (self::MARK_OPTIONS as $option)
		{
			$values[$option->value] = ($options[$option->value] ?? null)?->getValue();
		}

		return $this->makePermission($values);
	}

	/**
	 * Whether the permission to collect the auto check-ins of the employee is withheld - the plaque over the
	 * timeline of his day: the auto check-ins are switched on in at least one department he is a member of,
	 * and he has not granted the permission, whether he ever saw the popup or not. It is the rule the mark
	 * of his row in the presence list is put by ({@see AutoCheckInConsentRule}). The name is left from the
	 * rule it replaced, which asked for a postponement in force: the method is called from stafftrackmobile,
	 * and the two modules are updated apart.
	 *
	 * The permission is granted by the mark granted, or by the marker of the consent the employee keeps - the
	 * one the tracker of his device collects the routes by - whatever the mark says. Neither the scenarios of
	 * the mark nor its count, the limit or its date weigh, and a department the employee only manages does not
	 * count. A setting of the departments nobody could read answers no, so does a marker nobody could read, and
	 * so does a region where geo features are not available: no auto check-in is collected there.
	 *
	 * The mark is read first, the region next, then the marker - one reading of the user options of main, mostly
	 * off its cache - and the departments last, by two queries of the structure: a granted permission costs the
	 * one reading of the options of the employee, and an identifier that names no employee costs nothing at all.
	 *
	 * No rights are checked: whether the caller may learn this of the employee is for the transport to
	 * decide before asking, and so is the day it is asked for. Nothing is written, and no cache is dropped.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isPostponementInForce(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->consentRule->isWithheldForUser($userId, $this->getPermission($userId)?->state);
	}

	/**
	 * Whether the employee has postponed the popup as many times in a row as it asks at most. Built on
	 * the read of the mark above, so it asks the storage just as often and drops an identifier that names
	 * no employee the same way - without the storage being asked.
	 *
	 * Only a postponed mark can reach the limit: a granted one answers no whatever count may be left in
	 * the storage, because the permission the popup asks for has already been given.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isPostponementLimitReached(int $userId): bool
	{
		$permission = $this->getPermission($userId);

		return $permission !== null && $this->hasReachedPostponementLimit($permission);
	}

	/**
	 * Serves the manager interface, which reads the employees of its departments at once, so the
	 * storage is asked directly: the cache of the option provider holds one user at a time.
	 *
	 * @param int[] $userIds
	 * @return array<int, CheckInGeoPermissionDto|null> a value per real employee of the list; null where
	 *     the employee has no mark at all. An identifier that names no employee is dropped and gets no
	 *     key in the answer, so a list of none of them comes back empty
	 */
	public function getPermissions(array $userIds): array
	{
		$userIds = $this->filterUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$storedValues = [];
		foreach ($this->fetchOptionRows($userIds) as $row)
		{
			$storedValues[(int)$row['USER_ID']][$row['NAME']] = $row['VALUE'];
		}

		$result = [];
		foreach ($userIds as $userId)
		{
			$result[$userId] = $this->makePermission($storedValues[$userId] ?? []);
		}

		return $result;
	}

	/**
	 * One query whatever the size of the list, filtered by the pair the unique index of the storage is
	 * built on.
	 *
	 * @param int[] $userIds
	 * @return array
	 */
	protected function fetchOptionRows(array $userIds): array
	{
		$optionNames = array_map(
			static fn(Option $option): string => $option->value,
			self::MARK_OPTIONS,
		);

		return OptionTable::query()
			->setSelect(['USER_ID', 'NAME', 'VALUE'])
			->whereIn('USER_ID', $userIds)
			->whereIn('NAME', $optionNames)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param array<string, string|null> $values
	 * @return CheckInGeoPermissionDto|null
	 */
	private function makePermission(array $values): ?CheckInGeoPermissionDto
	{
		$state = CheckInGeoPermissionState::tryFrom(
			(string)($values[Option::CHECKIN_GEO_PERMISSION_STATE->value] ?? ''),
		);
		if ($state === null)
		{
			return null;
		}

		$postponedAt = (int)($values[Option::CHECKIN_GEO_DECLINED_AT->value] ?? 0);

		return new CheckInGeoPermissionDto(
			$state,
			CheckInGeoPermissionScenario::deserialize(
				(string)($values[Option::CHECKIN_GEO_DECLINED_SCENARIOS->value] ?? ''),
			),
			(int)($values[Option::CHECKIN_GEO_DECLINED_COUNT->value] ?? 0),
			$postponedAt > 0 ? $postponedAt : null,
		);
	}

	/**
	 * Whether a mark already read has reached the limit: the storage is not asked for it twice.
	 *
	 * @param CheckInGeoPermissionDto $permission
	 * @return bool
	 */
	private function hasReachedPostponementLimit(CheckInGeoPermissionDto $permission): bool
	{
		return
			$permission->state === CheckInGeoPermissionState::POSTPONED
			&& $permission->postponedCount >= self::POSTPONEMENT_LIMIT
		;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function filterUserIds(array $userIds): array
	{
		$result = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$result[$userId] = $userId;
			}
		}

		return array_values($result);
	}

	/**
	 * @return CheckInGeoPermissionScenario[]
	 */
	private function getEnabledScenarios(): array
	{
		$scenarios = [];

		if ($this->settingsProvider->isAutoCheckInEnabledInSettings())
		{
			$scenarios[] = CheckInGeoPermissionScenario::AUTO_CHECK_IN;
		}

		if ($this->settingsProvider->isDayStartCheckInEnabled())
		{
			$scenarios[] = CheckInGeoPermissionScenario::DAY_START_CHECK_IN;
		}

		return $scenarios;
	}

	private function getPostponedCount(int $userId): int
	{
		$value = $this->optionProvider
			->getOption($userId, Option::CHECKIN_GEO_DECLINED_COUNT)
			?->getValue()
		;

		return (int)($value ?? 0);
	}
}
