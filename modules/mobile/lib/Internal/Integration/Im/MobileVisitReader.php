<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Im;

use Bitrix\Im\Model\StatusTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\Main\UserOptionReader;

/**
 * Integration layer for the visits of the mobile application, read for a whole pack of users at
 * once. All other classes of the shared facts must use this class instead of reading the presence
 * record of im or the activity options of the application directly.
 *
 * The last visit is the presence record: its writer (Bitrix\Mobile\User::setOnline) stamps it on
 * every request and falls back to an option of its own when im is absent, and the pair is read in the
 * same order. The per-platform options are the first activity instead: the same writer refreshes them
 * at most once in 30 days, so they say when the application came into use, not when it was last
 * opened, and the two must not be merged.
 *
 * A user without a visit has no entry. The reader always answers - without im it falls back to the
 * option - and a read that fails throws what it met, since a failure is not an unknown; null is left to
 * a reader that cannot answer at all.
 */
class MobileVisitReader
{
	private const OPTION_CATEGORY = 'mobile';
	private const LAST_VISIT_OPTION = 'lastActivityDate';
	private const PLATFORM_ACTIVITY_OPTIONS = ['iOsLastActivityDate', 'AndroidLastActivityDate'];

	/** How many users one read of the presence covers. */
	private const CHUNK_SIZE = 500;

	/**
	 * @param int[] $userIds
	 * @return array<int, DateTime>|null
	 */
	public function getLastVisits(array $userIds): ?array
	{
		$userIds = self::normalizeIds($userIds);
		if (!$this->hasPresenceStorage())
		{
			return $this->readOptionMoments($userIds, self::LAST_VISIT_OPTION);
		}

		$visits = [];
		foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk)
		{
			$visits += self::readPresence($chunk);
		}

		return $visits;
	}

	/**
	 * The earliest mobile activity of each user over the platforms of the application.
	 *
	 * @param int[] $userIds
	 * @return array<int, DateTime>|null
	 */
	public function getFirstActivities(array $userIds): ?array
	{
		$userIds = self::normalizeIds($userIds);

		$earliest = [];
		foreach (self::PLATFORM_ACTIVITY_OPTIONS as $optionName)
		{
			foreach ($this->readOptionMoments($userIds, $optionName) as $userId => $moment)
			{
				if (!isset($earliest[$userId]) || $moment->getTimestamp() < $earliest[$userId]->getTimestamp())
				{
					$earliest[$userId] = $moment;
				}
			}
		}

		return $earliest;
	}

	/**
	 * Whether the presence record is available as the source of the visit. The im module owns it,
	 * and its absence is what sends the read to the option fallback.
	 */
	protected function hasPresenceStorage(): bool
	{
		return Loader::includeModule('im');
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string> userId => the stored value of the option, serialized
	 */
	protected function readOption(array $userIds, string $optionName): array
	{
		return UserOptionReader::read($userIds, self::OPTION_CATEGORY, $optionName);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, DateTime>
	 */
	private static function readPresence(array $userIds): array
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
	 * A record whose value is not a positive serialized timestamp is no moment.
	 *
	 * @param int[] $userIds
	 * @return array<int, DateTime>
	 */
	private function readOptionMoments(array $userIds, string $optionName): array
	{
		$moments = [];
		foreach ($this->readOption($userIds, $optionName) as $userId => $value)
		{
			$timestamp = (int)unserialize($value, ['allowed_classes' => false]);
			if ($timestamp > 0)
			{
				$moments[$userId] = DateTime::createFromTimestamp($timestamp);
			}
		}

		return $moments;
	}

	/**
	 * @param array<int|string> $userIds
	 * @return int[]
	 */
	private static function normalizeIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
	}
}
