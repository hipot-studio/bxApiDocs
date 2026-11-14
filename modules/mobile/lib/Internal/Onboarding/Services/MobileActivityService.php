<?php

namespace Bitrix\Mobile\Internal\Onboarding\Services;

use Bitrix\Im\Model\StatusTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Storage\UserOptionReader;

class MobileActivityService
{
	private const OPTION_CATEGORY = 'mobile';
	private const IOS_ACTIVITY_OPTION = 'iOsLastActivityDate';
	private const ANDROID_ACTIVITY_OPTION = 'AndroidLastActivityDate';
	private const LAST_VISIT_OPTION = 'lastActivityDate';

	/** How many users one read of the preloaded presence covers. */
	private const VISIT_CHUNK_SIZE = 500;

	/**
	 * Users the caller preloaded the visit of, as a set, or null when nothing is preloaded and
	 * every read goes to the storage.
	 *
	 * @var array<int, true>|null
	 */
	private ?array $visitScope = null;

	/**
	 * Visits of the preloaded selection, read at the first ask. The read is deferred because
	 * most passes never ask for a visit at all - the implementation help comes due on a couple
	 * of days of the whole campaign - and the pass that does ask, asks for the selection.
	 *
	 * @var array<int, DateTime>|null
	 */
	private ?array $visits = null;

	/**
	 * Declares the selection whose visits are read in one go. Both sources of the visit are
	 * read by the selection, the fallback option included: it is the one the whole pass would
	 * otherwise ask about one user at a time.
	 *
	 * @param int[] $userIds
	 */
	public function preloadLastVisits(array $userIds): void
	{
		$scope = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$scope[$userId] = true;
			}
		}

		$this->visitScope = $scope;
		$this->visits = null;
	}

	public function getActivityDate(int $userId): ?DateTime
	{
		$iosTimestamp = $this->getActivityTimestamp($userId, self::IOS_ACTIVITY_OPTION);
		$androidTimestamp = $this->getActivityTimestamp($userId, self::ANDROID_ACTIVITY_OPTION);

		$activityTimestamp = $this->getEarliestTimestamp($iosTimestamp, $androidTimestamp);

		if ($activityTimestamp === null)
		{
			return null;
		}

		return DateTime::createFromTimestamp($activityTimestamp);
	}

	/**
	 * Last mobile visit of the user - the "opened the app after the meetup" signal of the final
	 * pushes. The earliest activity of getActivityDate() stays the user selection signal, so the
	 * two must not be merged.
	 *
	 * The per-platform options are deliberately not the source here: their writer
	 * (Bitrix\Mobile\User::setOnline) refreshes them at most once in 30 days, which outlives the
	 * whole campaign (schedule.maxPortalAgeDays), so a visit made inside the series would never
	 * move them. The same writer stamps every request into the presence record, and falls back to
	 * an option of its own when im is absent - this method reads the pair in the same order.
	 */
	public function getLastVisitDate(int $userId): ?DateTime
	{
		if ($userId <= 0)
		{
			return null;
		}

		if (isset($this->visitScope[$userId]))
		{
			return $this->preloadedVisits()[$userId] ?? null;
		}

		return $this->readLastVisit($userId);
	}

	/**
	 * Visits of the preloaded selection, read once. A user of the selection without a presence
	 * record simply has no entry, so a second ask about them costs nothing either.
	 *
	 * @return array<int, DateTime>
	 */
	private function preloadedVisits(): array
	{
		if ($this->visits !== null)
		{
			return $this->visits;
		}

		$userIds = array_keys($this->visitScope ?? []);

		if (!$this->hasPresenceStorage())
		{
			$this->visits = $this->readVisitOptions($userIds);

			return $this->visits;
		}

		$this->visits = [];
		foreach (array_chunk($userIds, self::VISIT_CHUNK_SIZE) as $chunk)
		{
			$this->visits += $this->readPresenceChunk($chunk);
		}

		return $this->visits;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, DateTime>
	 */
	private function readPresenceChunk(array $userIds): array
	{
		$rows = StatusTable::query()
			->setSelect(['USER_ID', 'MOBILE_LAST_DATE'])
			->whereIn('USER_ID', $userIds)
			->fetchAll()
		;

		$visits = [];
		foreach ($rows as $row)
		{
			if ($row['MOBILE_LAST_DATE'] instanceof DateTime)
			{
				$visits[(int)$row['USER_ID']] = $row['MOBILE_LAST_DATE'];
			}
		}

		return $visits;
	}

	/**
	 * The fallback option of the selection, read the same way the last-push state of the pass
	 * is read. A record whose value is not a positive timestamp is no visit.
	 *
	 * @param int[] $userIds
	 * @return array<int, DateTime>
	 */
	private function readVisitOptions(array $userIds): array
	{
		$visits = [];

		foreach (UserOptionReader::read($userIds, self::OPTION_CATEGORY, self::LAST_VISIT_OPTION) as $userId => $value)
		{
			$timestamp = (int)unserialize($value, ['allowed_classes' => false]);
			if ($timestamp > 0)
			{
				$visits[$userId] = DateTime::createFromTimestamp($timestamp);
			}
		}

		return $visits;
	}

	/**
	 * Whether the presence record is available as the source of the visit. The im module owns
	 * it, and its absence is what sends every read to the option fallback.
	 */
	protected function hasPresenceStorage(): bool
	{
		return Loader::includeModule('im');
	}

	private function readLastVisit(int $userId): ?DateTime
	{
		if ($this->hasPresenceStorage())
		{
			$row = StatusTable::query()
				->setSelect(['MOBILE_LAST_DATE'])
				->where('USER_ID', $userId)
				->setLimit(1)
				->fetch()
			;

			$lastVisit = $row['MOBILE_LAST_DATE'] ?? null;

			return $lastVisit instanceof DateTime ? $lastVisit : null;
		}

		$timestamp = $this->getActivityTimestamp($userId, self::LAST_VISIT_OPTION);

		return $timestamp === null ? null : DateTime::createFromTimestamp($timestamp);
	}

	/**
	 * @return array<int, int>
	 */
	public function getActivityTimestampsByUser(): array
	{
		$timestamps = [];
		foreach ([self::IOS_ACTIVITY_OPTION, self::ANDROID_ACTIVITY_OPTION] as $optionName)
		{
			$result = \CUserOptions::GetList([], [
				'CATEGORY' => mb_strtolower(self::OPTION_CATEGORY),
				'NAME' => mb_strtolower($optionName),
			]);

			while ($row = $result->Fetch())
			{
				$userId = (int)$row['USER_ID'];
				if ($userId <= 0)
				{
					continue;
				}

				$timestamp = (int)unserialize($row['VALUE'], ['allowed_classes' => false]);
				if ($timestamp <= 0)
				{
					continue;
				}

				$timestamps[$userId] = isset($timestamps[$userId])
					? min($timestamps[$userId], $timestamp)
					: $timestamp;
			}
		}

		return $timestamps;
	}

	/**
	 * @return int[]
	 */
	public function getUserIdsWithActivity(): array
	{
		return array_keys($this->getActivityTimestampsByUser());
	}

	public function hasMobileActivity(int $userId): bool
	{
		$iosTimestamp = $this->getActivityTimestamp($userId, self::IOS_ACTIVITY_OPTION);
		$androidTimestamp = $this->getActivityTimestamp($userId, self::ANDROID_ACTIVITY_OPTION);

		return $iosTimestamp !== null || $androidTimestamp !== null;
	}

	public function getDaysSinceActivity(int $userId): ?int
	{
		$activityDate = $this->getActivityDate($userId);
		if ($activityDate === null)
		{
			return null;
		}

		$now = new DateTime();
		$diff = $now->getTimestamp() - $activityDate->getTimestamp();

		return (int)floor($diff / (60 * 60 * 24));
	}

	public function clearActivity(int $userId): bool
	{
		$deletedIos = \CUserOptions::DeleteOption(self::OPTION_CATEGORY, self::IOS_ACTIVITY_OPTION, false, $userId);
		$deletedAndroid = \CUserOptions::DeleteOption(self::OPTION_CATEGORY, self::ANDROID_ACTIVITY_OPTION, false, $userId);

		return $deletedIos || $deletedAndroid;
	}

	public function clearAllUsersActivity(): bool
	{
		$deletedIos = \CUserOptions::DeleteOptionsByName(self::OPTION_CATEGORY, self::IOS_ACTIVITY_OPTION);
		$deletedAndroid = \CUserOptions::DeleteOptionsByName(self::OPTION_CATEGORY, self::ANDROID_ACTIVITY_OPTION);

		return $deletedIos || $deletedAndroid;
	}

	private function getActivityTimestamp(int $userId, string $optionName): ?int
	{
		$value = \CUserOptions::GetOption(self::OPTION_CATEGORY, $optionName, null, $userId);

		if (empty($value))
		{
			return null;
		}

		$timestamp = (int)$value;

		return $timestamp > 0 ? $timestamp : null;
	}

	private function getEarliestTimestamp(?int $timestamp1, ?int $timestamp2): ?int
	{
		if ($timestamp1 === null)
		{
			return $timestamp2;
		}

		if ($timestamp2 === null)
		{
			return $timestamp1;
		}

		return min($timestamp1, $timestamp2);
	}

}
