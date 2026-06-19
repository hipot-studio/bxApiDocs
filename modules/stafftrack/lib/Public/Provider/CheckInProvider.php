<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;
use Bitrix\StaffTrack\Public\Services\MapDataCache;
use Bitrix\Mobile\Provider\UserRepository;

class CheckInProvider
{
	private const DAY_END_OFFSET = 86399;
	private const MAX_TIMEZONE_OFFSET = 50400;

	private static ?self $instance = null;
	private CheckInRepository $repository;

	public function __construct()
	{
		$this->repository = new CheckInRepository();
	}

	/**
	 * @return self
	 */
	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @param int $userId
	 * @return array
	 */
	public function getSettings(int $userId): array
	{
		$value =
			OptionProvider::getInstance()
				->getOption($userId, Option::TIMEMAN_INTEGRATION_ENABLED)
				?->getValue()
		;

		return [
			'isEnabledBySettings' => Feature::isCheckInEnabledBySettings(),
			'isTimemanAvailable' => WorkDayService::isAvailable(),
			'isTimemanIntegrationEnabled' => ($value ?? 'Y') === 'Y',
		];
	}

	private function subscribeToPush(int $userId): void
	{
		Pull\PushSender::subscribeToTag(Pull\Tag::getUserTag($userId));
	}

	/**
	 * @param int $timezoneOffset
	 * @param int $timestamp
	 * @return array
	 */
	public function getLatestUsersCheckInByDepartment(int $timezoneOffset = 0, int $timestamp = 0): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return [];
		}

		$this->subscribeToPush($currentUserId);

		$subordinateIds = DepartmentProvider::getSubordinateIds($currentUserId);

		if (!empty($subordinateIds))
		{
			$userIds = array_values(array_unique(array_merge([$currentUserId], $subordinateIds)));
		}
		else
		{
			$userIds = [$currentUserId];
		}

		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);

		if ($this->isPastLocalDate($requestedDate, $timezoneOffset))
		{
			$cached = MapDataCache::get($currentUserId, $requestedDate, $userIds);
			if ($cached !== null)
			{
				return self::attachUsers($cached);
			}

			$result = $this->getLatestUsersCheckIn($userIds, $timezoneOffset, $timestamp);
			MapDataCache::set($currentUserId, $requestedDate, $userIds, $result);

			return self::attachUsers($result);
		}

		return self::attachUsers(
			$this->getLatestUsersCheckIn($userIds, $timezoneOffset, $timestamp),
		);
	}

	private static function attachUsers(array $checkIns): array
	{
		$userIds = [];
		foreach ($checkIns as $checkIn)
		{
			$userId = (int)($checkIn['userId'] ?? 0);
			if ($userId > 0)
			{
				$userIds[$userId] = true;
			}
		}

		if (empty($userIds))
		{
			return $checkIns;
		}

		$usersById = [];
		foreach (UserRepository::getByIds(array_keys($userIds)) as $userDto)
		{
			$usersById[$userDto->id] = $userDto;
		}

		foreach ($checkIns as &$checkIn)
		{
			$userId = (int)($checkIn['userId'] ?? 0);
			$checkIn['user'] = isset($usersById[$userId]) ? [$usersById[$userId]] : [];
		}
		unset($checkIn);

		return $checkIns;
	}

	private function isPastLocalDate(string $requestedDate, int $timezoneOffset): bool
	{
		$todayLocalDate = date('Y-m-d', time() + $timezoneOffset);

		return $requestedDate < $todayLocalDate;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int $timezoneOffset
	 * @return array
	 */
	public function getEmployeeRoute(int $userId, int $timestamp, int $timezoneOffset): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return [
				'route' => [],
				'isDayClosed' => false,
			];
		}

		$this->subscribeToPush($currentUserId);
		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$checkInCollection = $this->repository->getUserRouteByPeriod($userId, $dateStart, $dateEnd);
		$aggregatedCheckIns = $this->repository->getAggregatedPointsByPeriod($userId, $dateStart, $dateEnd);

		$result = $this->filterAndNormalizeCheckIns($checkInCollection, $aggregatedCheckIns, $requestedDate, $timezoneOffset, $currentUserId);

		return [
			'route' => $result,
			'isDayClosed' => WorkDayService::isDayClosed($userId, $timestamp, $timezoneOffset),
		];
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int $timezoneOffset
	 * @return array
	 */
	public function getUserCheckInByDate(int $userId, int $timestamp, int $timezoneOffset = 0): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return [];
		}

		$this->subscribeToPush($currentUserId);
		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$checkInCollection = $this->repository->getUserCheckInByPeriod($userId, $dateStart, $dateEnd);
		$aggregatedCheckIns = $this->repository->getAggregatedPointsByPeriod($userId, $dateStart, $dateEnd);

		$result = $this->filterAndNormalizeCheckIns($checkInCollection, $aggregatedCheckIns, $requestedDate, $timezoneOffset, $currentUserId);

		return [
			'checkIns' => $result,
			'isDayClosed' => WorkDayService::isDayClosed($userId, $timestamp, $timezoneOffset),
		];
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int|null $timezoneOffset
	 * @return int
	 */
	public function getManualCheckInCountByDate(int $userId, int $timestamp, ?int $timezoneOffset = null): int
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();

		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$rows = $this->repository->getCheckInRowsForAggregation($userId, $dateStart, $dateEnd);

		$count = 0;
		foreach ($rows as $row)
		{
			if (
				(int)($row['ENTITY_TYPE'] ?? 0) === CheckInType::MANUAL->value
				&& $this->isCheckInOnLocalDate($row, $requestedDate)
			)
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @param array $userIds
	 * @param int $timezoneOffset
	 * @param int $timestamp
	 * @return array
	 */
	private function getLatestUsersCheckIn(array $userIds, int $timezoneOffset = 0, int $timestamp = 0): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if (empty($userIds))
		{
			return [];
		}

		$statsByUser = $this->getDailyStatsByUsers($userIds, $timezoneOffset, $timestamp);
		if (empty($statsByUser))
		{
			return [];
		}

		$lastCheckInIds = $this->extractLastCheckInIds($statsByUser);
		$checkInsById = $this->resolveLastCheckIns($lastCheckInIds, $userIds, $timezoneOffset, $timestamp);

		return $this->buildLatestCheckInResult($statsByUser, $checkInsById, $timezoneOffset);
	}

	/**
	 * @param array<int, array> $statsByUser
	 * @return int[]
	 */
	private function extractLastCheckInIds(array $statsByUser): array
	{
		$ids = [];
		foreach ($statsByUser as $stats)
		{
			$id = (int)$stats['LAST_CHECK_IN_ID'];
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $lastCheckInIds
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function resolveLastCheckIns(array $lastCheckInIds, array $userIds, int $timezoneOffset, int $timestamp): array
	{
		if (empty($lastCheckInIds))
		{
			return [];
		}

		$checkInRows = $this->repository->getCheckInsByIds($lastCheckInIds);
		$checkInsById = array_column($checkInRows, null, 'ID');

		$unresolvedIds = array_diff($lastCheckInIds, array_map('intval', array_keys($checkInsById)));
		if (empty($unresolvedIds))
		{
			return $checkInsById;
		}

		foreach ($this->findAggregatedCheckInsByIds($unresolvedIds, $userIds, $timezoneOffset, $timestamp) as $id => $point)
		{
			$checkInsById[$id] = $point;
		}

		return $checkInsById;
	}

	/**
	 * @param int[] $unresolvedIds
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function findAggregatedCheckInsByIds(array $unresolvedIds, array $userIds, int $timezoneOffset, int $timestamp): array
	{
		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$aggregatedPoints = $this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd);
		$unresolvedLookup = array_flip($unresolvedIds);

		$found = [];
		foreach ($aggregatedPoints as $point)
		{
			$id = (int)($point['ID'] ?? 0);
			if (isset($unresolvedLookup[$id]))
			{
				$found[$id] = $point;
			}
		}

		return $found;
	}

	/**
	 * @param array<int, array> $statsByUser
	 * @param array<int, array> $checkInsById
	 * @return array<int, array>
	 */
	private function buildLatestCheckInResult(array $statsByUser, array $checkInsById, int $timezoneOffset): array
	{
		$result = [];
		foreach ($statsByUser as $stats)
		{
			$checkIn = $checkInsById[$stats['LAST_CHECK_IN_ID']] ?? null;
			if ($checkIn === null)
			{
				continue;
			}

			$normalized = CheckInNormalizer::normalize($checkIn, $timezoneOffset, false);
			unset($normalized['description']);
			$normalized['totalCountByDay'] = (int)$stats['CNT']
				+ (int)$stats['HAS_OPEN_ENTER']
			;

			$result[] = $normalized;
		}

		return $result;
	}

	/**
	 * @param array $userIds
	 * @param int $timezoneOffset
	 * @param int $timestamp
	 * @return array<int, array> userId => stats row
	 */
	private function getDailyStatsByUsers(array $userIds, int $timezoneOffset, int $timestamp = 0): array
	{
		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		$localDate = new \Bitrix\Main\Type\Date($requestedDate, 'Y-m-d');

		$statsRows = $this->repository->getDailyStatsByUsers($userIds, $localDate);

		$usersWithStats = array_map(static fn($row) => (int)$row['USER_ID'], $statsRows);
		$usersWithoutStats = array_diff($userIds, $usersWithStats);

		if (!empty($usersWithoutStats))
		{
			$this->backFillMissingStats($usersWithoutStats, $requestedDate, $localDate);
			$statsRows = $this->repository->getDailyStatsByUsers($userIds, $localDate);
		}

		$statsByUser = [];
		foreach ($statsRows as $row)
		{
			$statsByUser[(int)$row['USER_ID']] = $row;
		}

		return $statsByUser;
	}

	/**
	 * @param string $requestedDate 'Y-m-d'
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function getWideDayBoundaries(string $requestedDate): array
	{
		$midnightUtc = strtotime($requestedDate . ' 00:00:00 UTC');
		$endOfDayUtc = $midnightUtc + self::DAY_END_OFFSET;

		return [
			DateTime::createFromTimestamp($midnightUtc - self::MAX_TIMEZONE_OFFSET),
			DateTime::createFromTimestamp($endOfDayUtc + self::MAX_TIMEZONE_OFFSET),
		];
	}

	/**
	 * @param int|null $timestamp
	 * @param int $requesterTimezoneOffset
	 * @return string 'Y-m-d'
	 */
	private function getRequestedDate(?int $timestamp, int $requesterTimezoneOffset): string
	{
		if ($timestamp === null || $timestamp <= 0)
		{
			$timestamp = time();
		}

		return date('Y-m-d', $timestamp + $requesterTimezoneOffset);
	}

	private function filterAndNormalizeCheckIns(iterable $checkInCollection, array $aggregatedCheckIns, string $requestedDate, int $timezoneOffset, int $currentUserId): array
	{
		$filteredData = [];
		foreach ($checkInCollection as $item)
		{
			$values = is_array($item) ? $item : $item->collectValues();
			if ($this->isCheckInOnLocalDate($values, $requestedDate))
			{
				$filteredData[] = $values;
			}
		}

		foreach ($aggregatedCheckIns as $aggregatedCheckIn)
		{
			if ($this->isCheckInOnLocalDate($aggregatedCheckIn, $requestedDate))
			{
				$filteredData[] = $aggregatedCheckIn;
			}
		}

		usort($filteredData, static function ($a, $b) {
			$tsA = $a['DATE_CREATE'] instanceof DateTime ? $a['DATE_CREATE']->getTimestamp() : (int)$a['DATE_CREATE'];
			$tsB = $b['DATE_CREATE'] instanceof DateTime ? $b['DATE_CREATE']->getTimestamp() : (int)$b['DATE_CREATE'];

			return $tsA <=> $tsB ?: ((int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0));
		});

		$filesMap = (new FileResolver())->resolveMany(
			self::collectCheckInFiles($filteredData),
			$currentUserId,
		);

		$result = [];
		foreach ($filteredData as $item)
		{
			$checkInId = (int)($item['ID'] ?? 0);
			$result[] = CheckInNormalizer::normalize($item, $timezoneOffset, false, $filesMap[$checkInId] ?? []);
		}

		return $result;
	}

	/**
	 * @return array<int, array{id: int, fileIds: array}>
	 */
	private static function collectCheckInFiles(array $checkIns): array
	{
		$result = [];
		foreach ($checkIns as $checkIn)
		{
			$fileIds = is_array($checkIn['FILE_IDS'] ?? null) ? $checkIn['FILE_IDS'] : [];
			if (!empty($fileIds))
			{
				$result[] = [
					'id' => (int)($checkIn['ID'] ?? 0),
					'fileIds' => $fileIds,
				];
			}
		}

		return $result;
	}

	private function backFillMissingStats(array $userIds, string $requestedDate, Main\Type\Date $localDate): void
	{
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$allCheckInRows = $this->repository->getAllUsersCheckInByPeriod($userIds, $dateStart, $dateEnd);
		$allAggregatedPoints = $this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd);

		$checkInsByUser = [];
		foreach (array_merge($allCheckInRows, $allAggregatedPoints) as $row)
		{
			if ($this->isCheckInOnLocalDate($row, $requestedDate))
			{
				$checkInsByUser[(int)$row['USER_ID']][] = $row;
			}
		}

		$statsUpdater = new DailyStatsUpdater();

		foreach ($userIds as $userId)
		{
			$allCheckIns = $checkInsByUser[$userId] ?? [];
			if (empty($allCheckIns))
			{
				continue;
			}

			usort($allCheckIns, static function ($a, $b) {
				$tsA = $a['DATE_CREATE'] instanceof DateTime ? $a['DATE_CREATE']->getTimestamp() : (int)$a['DATE_CREATE'];
				$tsB = $b['DATE_CREATE'] instanceof DateTime ? $b['DATE_CREATE']->getTimestamp() : (int)$b['DATE_CREATE'];

				return $tsA <=> $tsB;
			});

			$statsUpdater->backFillForUser($userId, $allCheckIns, $localDate);
		}
	}

	private function isCheckInOnLocalDate(array $checkIn, string $requestedDate): bool
	{
		$dateCreate = $checkIn['DATE_CREATE'] ?? null;

		if ($dateCreate instanceof DateTime)
		{
			$timestamp = $dateCreate->getTimestamp();
		}
		elseif (is_int($dateCreate))
		{
			$timestamp = $dateCreate;
		}
		else
		{
			return false;
		}

		$userTimezone = (int)($checkIn['USER_TIMEZONE'] ?? 0);
		$localDate = date('Y-m-d', $timestamp + $userTimezone);

		return $localDate === $requestedDate;
	}
}
