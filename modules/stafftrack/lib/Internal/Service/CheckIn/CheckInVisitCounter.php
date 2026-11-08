<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Type\Date;
use Bitrix\StaffTrack\Public\Provider\CheckInMarkerNormalizer;
use Bitrix\StaffTrack\Public\Services\CheckInMarkerAggregator;

/**
 * How many visits an employee made in a local day - the number the badge on his avatar carries.
 *
 * A visit is a marker of {@see CheckInMarkerAggregator}, so the count is taken from the markers
 * themselves and never from a formula beside them: the timeline draws one row per marker and the map
 * one point, and a manual check-in standing on the stay the tracker recorded at the same place is one
 * marker, one row, one point - and one visit. Counted from the records instead (the CNT of the daily
 * stats) such a visit weighs two, which is how the badge came to show four where the day showed two.
 */
class CheckInVisitCounter
{
	/**
	 * Employees whose days one reading holds in memory. A folded day is decoded whole - measured, 0.7 MB an
	 * employee, 7 MB for a shift the app was never closed over - so the memory of a count follows this
	 * number rather than the team, which nothing caps.
	 */
	private const USERS_PER_READING = 25;

	private CheckInDayRowsReader $dayRowsReader;
	private CheckInMarkerAggregator $aggregator;

	public function __construct(
		?CheckInDayRowsReader $dayRowsReader = null,
		?CheckInMarkerAggregator $aggregator = null,
	)
	{
		$this->aggregator = $aggregator ?? new CheckInMarkerAggregator();
		$this->dayRowsReader = $dayRowsReader ?? new CheckInDayRowsReader(null, $this->aggregator);
	}

	/**
	 * The team in readings of USERS_PER_READING employees: each is raised by the two steps of
	 * {@see CheckInDayRowsReader::readMarkerRowsByUsers()} - a few queries a reading rather than two per
	 * member - and counted before the next one is read.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> employee id => visits of the day, zero for an employee who made none
	 */
	public function countByUsers(array $userIds, Date $localDate): array
	{
		$counts = [];
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		foreach (array_chunk($userIds, self::USERS_PER_READING) as $readingUserIds)
		{
			foreach ($this->dayRowsReader->readMarkerRowsByUsers($readingUserIds, $localDate) as $userId => $rows)
			{
				$counts[$userId] = $this->countMarkers(
					$this->aggregator->buildMarkers(AggregatorEventMapper::fromRows($rows)),
				);
			}
		}

		return $counts;
	}

	/**
	 * The markers a reader already has in hand, counted by the same rule - for the route of a single
	 * employee, where the day is built anyway and reading it a second time would only risk a second answer.
	 *
	 * A marker with no coordinates is left out, and by the point it is really drawn at
	 * ({@see CheckInMarkerNormalizer::resolveReferencePoint()}): the map cannot place such a marker and
	 * the plaque of the route does not count it either (countSignificantEvents of the mobile bundle
	 * filters the markers by lat/lon), so the badge must not count it.
	 *
	 * @param array<int, array> $markers
	 */
	public function countMarkers(array $markers): int
	{
		$count = 0;
		foreach ($markers as $marker)
		{
			$reference = CheckInMarkerNormalizer::resolveReferencePoint($marker);
			if (($reference['latitude'] ?? null) !== null && ($reference['longitude'] ?? null) !== null)
			{
				$count++;
			}
		}

		return $count;
	}
}
