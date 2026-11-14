<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Mobile;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Whether an employee keeps the marker of his consent to the collection of his routes: the Tourist event of the
 * mobile client (LOCATION_PERMISSION_GRANTED_REMEMBERED of stafftrackmobile) remembered once he grants the permission
 * while the auto check-ins are on for him, and forgotten once he turns down the popup about them. The tracker of his
 * device collects the routes by this very marker, and a client older than stafftrackmobile 26.600.0 never reports the
 * grant to the server: the marker is what tells such a grant.
 *
 * The module mobile has no public way to read the events of somebody else - Tourist::getEvents() reads the ones of
 * the user of the session - so its storage is read here, and its format is the contract this class stands on: the
 * user option `tourist_events` of the category `mobile`, an array of the remembered events keyed by their ids, each
 * of them an array of its time, count and context, where a forgotten event has no key. A change of that format in
 * mobile breaks this reading without a word: the coupling is the price of the marker.
 *
 * The events are taken the way the client takes them: the marker stands where its event is an array, and the common
 * value of the option stands for an employee who has none of his own. No option, a value that is no array of events
 * and a portal without mobile mean no marker. The fake mode of Tourist, a switch for testing mobile, is not looked
 * at: it hides the events from the client and leaves the storage as it is. A storage that did not answer throws, and
 * what that means is for the caller to decide. No rights are checked.
 */
class TouristConsentMarkerReader
{
	public const EVENT_ID = 'stafftrack_check_in_v2_location_permission_granted';

	private const MODULE_ID = 'mobile';
	private const OPTION_CATEGORY = 'mobile';
	private const OPTION_NAME = 'tourist_events';

	/**
	 * One employee, by the options of main: they are cached by employee and category, so the reading mostly asks
	 * no storage at all.
	 *
	 * @param int $userId
	 * @return bool whether the employee keeps the marker; false for an id that is not positive
	 */
	public function hasMarker(int $userId): bool
	{
		if ($userId <= 0 || !$this->isMobileInstalled())
		{
			return false;
		}

		return self::isMarkerIn($this->readEvents($userId));
	}

	/**
	 * The employees of a list by one query, whatever their number: the options of main would ask the storage of
	 * each of them apart. The value of an employee is the one he has, and the common one where he has none.
	 *
	 * @param int[] $userIds
	 * @return array<int, bool> an answer for every real employee of the list
	 */
	public function hasMarkers(array $userIds): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$markers = array_fill_keys($userIds, false);
		if (!$this->isMobileInstalled())
		{
			return $markers;
		}

		$ownValues = [];
		$commonValue = null;
		foreach ($this->fetchEventRows($userIds) as $row)
		{
			$userId = (int)$row['USER_ID'];
			if ($userId === 0)
			{
				if ($row['COMMON'] === 'Y')
				{
					$commonValue = $row['VALUE'];
				}
			}
			elseif (isset($markers[$userId]))
			{
				$ownValues[$userId] = $row['VALUE'];
			}
		}

		foreach ($userIds as $userId)
		{
			$value = array_key_exists($userId, $ownValues) ? $ownValues[$userId] : $commonValue;
			$markers[$userId] = is_string($value) && self::isMarkerIn(self::unserializeEvents($value));
		}

		return $markers;
	}

	protected function isMobileInstalled(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	/**
	 * @return mixed the events as main keeps them; null where there is no option
	 */
	protected function readEvents(int $userId): mixed
	{
		return \CUserOptions::GetOption(self::OPTION_CATEGORY, self::OPTION_NAME, null, $userId);
	}

	/**
	 * The option of every employee of the list and the common one, by the unique index of the options.
	 *
	 * @param int[] $userIds real employees
	 * @return array<int, array{USER_ID: int|string, COMMON: string, VALUE: string|null}>
	 */
	protected function fetchEventRows(array $userIds): array
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		return $connection->query(
			'SELECT ' . $sqlHelper->quote('USER_ID') . ', ' . $sqlHelper->quote('COMMON')
			. ', ' . $sqlHelper->quote('VALUE')
			. ' FROM ' . $sqlHelper->quote('b_user_option')
			. ' WHERE ' . $sqlHelper->quote('USER_ID') . ' IN (' . implode(', ', [0, ...$userIds]) . ')'
			. ' AND ' . $sqlHelper->quote('CATEGORY') . ' = ' . $sqlHelper->convertToDbString(self::OPTION_CATEGORY)
			. ' AND ' . $sqlHelper->quote('NAME') . ' = ' . $sqlHelper->convertToDbString(self::OPTION_NAME),
		)->fetchAll();
	}

	private static function isMarkerIn(mixed $events): bool
	{
		return is_array($events) && is_array($events[self::EVENT_ID] ?? null);
	}

	/**
	 * Read the way main reads its options, without objects. A value that cannot be read is no array of events, and
	 * so no marker: the notice it would raise is not let fail the answer about the others.
	 */
	private static function unserializeEvents(string $value): mixed
	{
		return @unserialize($value, ['allowed_classes' => false]);
	}

	/**
	 * @param array $userIds
	 * @return int[]
	 */
	private static function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}
}
