<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CheckInMarkerAggregator
{
	public const KIND_MANUAL = 'manual';
	public const KIND_STAY = 'stay';
	public const KIND_MERGED = 'merged';

	private const KIND_TECHNICAL = 'technical';

	private const DESCRIPTION_ENTER = 'ENTER';
	private const DESCRIPTION_LEAVE = 'LEAVE';

	private const MERGE_RADIUS_METERS = 30;
	private const STOP_RADIUS_METERS = 50;
	// Silence splits a visit, in step with CheckInProvider::GAP_THRESHOLD_SEC: how long the track may
	// confirm nothing before a manual check-in and a stay at the same place count as two visits.
	private const MERGE_UNSEEN_GAP_MAX_SEC = 1800;
	// How long the day may say nothing before the coverage of a stay breaks. The same silence as the one
	// above and for the same reason, kept apart from it on purpose: that one decides whether two rows are
	// one visit, this one decides what a card is allowed to claim about a stay.
	private const COVERAGE_UNSEEN_GAP_MAX_SEC = 1800;
	private const EARTH_RADIUS_METERS = 6_371_000;

	private const LOGGER_ID = 'stafftrack.check_in.marker_aggregator';

	private ?LoggerInterface $logger = null;

	/**
	 * @param array<int, array{id: int, entityType: int, description: string, latitude: ?float, longitude: ?float, address: string, timestamp: int, userTimezone: int}> $events
	 * @return array<int, array{kind: string, event: array, duration: ?int, observedDuration: ?int, auto: ?array, timestamp: int, endTimestamp: int}>
	 */
	public function buildMarkers(array $events, ?int $dayCloseTimestamp = null): array
	{
		$items = $this->collapseAutoCheckIns($events, $dayCloseTimestamp);
		$merged = $this->mergeManualWithStays($items);

		return $this->filterTechnical($merged);
	}

	/**
	 * The spans of the day whose technical points can change what {@see buildMarkers()} answers: the gaps
	 * of every manual check-in and stay isNear() lets be one stop. A superset of the pairs the merge really
	 * evaluates - a span too many costs rows and changes no marker. Only marker events of $events are read.
	 *
	 * @param array<int, array{id: int, entityType: int, description: string, latitude: ?float, longitude: ?float, address: string, timestamp: int, userTimezone: int}> $events
	 * @return array<int, array{0: int, 1: int}> disjoint spans (low, high), the bounds themselves excluded
	 */
	public function collectMergeGapIntervals(array $events, ?int $dayCloseTimestamp = null): array
	{
		$items = $this->collapseAutoCheckIns($events, $dayCloseTimestamp);

		$manuals = [];
		$stays = [];
		foreach ($items as $item)
		{
			if ($item['kind'] === self::KIND_MANUAL)
			{
				$manuals[] = $item;
			}
			elseif ($item['kind'] === self::KIND_STAY)
			{
				$stays[] = $item;
			}
		}

		$intervals = [];
		foreach ($manuals as $manual)
		{
			foreach ($stays as $stay)
			{
				if (!$this->isNear($manual['event'], $stay['event']))
				{
					continue;
				}

				[$low, $high] = $this->gapInterval($manual, $stay);
				// A manual check-in taken inside the stay gives reversed bounds and no points at all,
				// the same empty set isSameVisit() walks for such a pair.
				if ($low >= $high)
				{
					continue;
				}

				$intervals[] = [$low, $high];
			}
		}

		return $this->mergeIntervals($intervals);
	}

	/**
	 * @param array<int, array{0: int, 1: int}> $intervals
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function mergeIntervals(array $intervals): array
	{
		usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

		$merged = [];
		foreach ($intervals as [$low, $high])
		{
			$last = array_key_last($merged);
			if ($last !== null && $low <= $merged[$last][1])
			{
				$merged[$last][1] = max($merged[$last][1], $high);
				continue;
			}

			$merged[] = [$low, $high];
		}

		return $merged;
	}

	/**
	 * @param array<int, array> $events
	 * @return array<int, array>
	 */
	private function collapseAutoCheckIns(array $events, ?int $dayCloseTimestamp): array
	{
		$events = $this->ensureOrderedByTime($events);

		$items = [];
		$openEnter = null;
		$coveredUntil = null;

		foreach ($events as $event)
		{
			// A manual check-in never closes an open stay: the real LEAVE stays the visit boundary,
			// the same open-enter model as DailyStatsUpdater::calculateDelta().
			if ($event['entityType'] === CheckInType::MANUAL->value)
			{
				$items[] = $this->makeManual($event);
				$coveredUntil = $this->extendCoverage($coveredUntil, $event['timestamp']);
				continue;
			}

			if ($event['description'] === self::DESCRIPTION_ENTER)
			{
				if ($openEnter !== null)
				{
					// The arrival that closes the stay is a record like any other: it carries the coverage
					// if it follows one closely enough, and it ends the silence otherwise.
					$items[] = $this->makeStay(
						$openEnter,
						$event['timestamp'],
						$this->extendCoverage($coveredUntil, $event['timestamp']),
					);
				}

				$openEnter = $event;
				$coveredUntil = $event['timestamp'];
				continue;
			}

			if ($event['description'] === self::DESCRIPTION_LEAVE && $openEnter !== null)
			{
				// The tracker dates a LEAVE by stayObservedUntil - the moment it last saw the stay -
				// so a departure of its own answers for the whole stay however silent it was.
				$items[] = $this->makeStay($openEnter, $event['timestamp'], $event['timestamp']);
				$openEnter = null;
				$coveredUntil = null;
				continue;
			}

			// Everything else, a departure with no stay behind it included, is a record of the day and
			// nothing more: it carries the coverage of whatever stay is open, and opens none of its own.
			$items[] = $this->makeTechnical($event);
			$coveredUntil = $this->extendCoverage($coveredUntil, $event['timestamp']);
		}

		if ($openEnter !== null)
		{
			$closeTimestamp = $this->resolveOpenEnterClose($openEnter, $dayCloseTimestamp);
			$items[] = $this->makeStay(
				$openEnter,
				$closeTimestamp,
				$this->extendCoverage($coveredUntil, $closeTimestamp),
			);
		}

		return $items;
	}

	/**
	 * How far the records of the day carry the coverage of the stay that is open. A stay writes nothing
	 * while it holds, so coverage is not a count of points but a chain of them: it reaches the next record
	 * while the step to it is a step the track may take in silence, and stops at the first one that is not.
	 */
	private function extendCoverage(?int $coveredUntil, int $timestamp): ?int
	{
		if ($coveredUntil === null || $timestamp - $coveredUntil > self::COVERAGE_UNSEEN_GAP_MAX_SEC)
		{
			return $coveredUntil;
		}

		return $timestamp;
	}

	/**
	 * Both entry points of the class collapse the day here, so the order the merge relies on is settled
	 * once. A day that breaks it is sorted and reported rather than refused, see reportUnorderedEvents().
	 *
	 * @param array<int, array> $events
	 * @return array<int, array>
	 */
	private function ensureOrderedByTime(array $events): array
	{
		if ($this->isOrderedByTime($events))
		{
			return $events;
		}

		$this->reportUnorderedEvents(count($events));

		// The same order the callers normalize a day into: time first, the id of the row to settle a tie.
		usort(
			$events,
			static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp'] ?: $a['id'] <=> $b['id'],
		);

		return $events;
	}

	/**
	 * @param array<int, array> $events
	 */
	private function isOrderedByTime(array $events): bool
	{
		$previous = null;
		foreach ($events as $event)
		{
			if ($previous !== null && $event['timestamp'] < $previous)
			{
				return false;
			}

			$previous = $event['timestamp'];
		}

		return true;
	}

	/**
	 * An unordered day is a defect of the caller, and the journal is the whole of the report: the call
	 * is not stopped anywhere, assertions or not. buildMarkers() answers the reading of an employee day,
	 * so a stop would turn a repairable input into a day that cannot be read at all.
	 */
	private function reportUnorderedEvents(int $eventCount): void
	{
		$this->getLogger()->warning('Check-in events came unordered by time, the marker build sorted them', [
			'eventCount' => $eventCount,
		]);
	}

	protected function getLogger(): LoggerInterface
	{
		// The registry is not asked on purpose: a broken precondition reaches the journal of any portal,
		// while .settings.php still decides the sink and the level of this id.
		$this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID, [], false) ?? new NullLogger();

		return $this->logger;
	}

	private function resolveOpenEnterClose(array $openEnter, ?int $dayCloseTimestamp): int
	{
		if ($dayCloseTimestamp !== null && $dayCloseTimestamp >= $openEnter['timestamp'])
		{
			return $dayCloseTimestamp;
		}

		return time();
	}

	/**
	 * The duration of a stay is the span between its own ends and needs no other moment. The observed part
	 * is how far past the arrival the records of the day still reach: the two part company exactly where
	 * the day stopped confirming anything.
	 *
	 * @param ?int $observedUntil how far the coverage of this stay reached, null when it reached nowhere
	 */
	private function makeStay(array $enter, int $closeTimestamp, ?int $observedUntil): array
	{
		$enterTimestamp = $enter['timestamp'];
		// The observed part is a part of the stay: it starts at the arrival and ends no later than the
		// stay does, whatever the caller hands over and whichever way round the ends of the day are.
		$observedBound = min($closeTimestamp, $observedUntil ?? $enterTimestamp);

		return [
			'kind' => self::KIND_STAY,
			'event' => $enter,
			'duration' => $closeTimestamp - $enterTimestamp,
			'observedDuration' => max(0, $observedBound - $enterTimestamp),
			'auto' => null,
			'timestamp' => $enterTimestamp,
			'endTimestamp' => $closeTimestamp,
		];
	}

	private function makeManual(array $event): array
	{
		return [
			'kind' => self::KIND_MANUAL,
			'event' => $event,
			'duration' => null,
			'observedDuration' => null,
			'auto' => null,
			'timestamp' => $event['timestamp'],
			'endTimestamp' => $event['timestamp'],
		];
	}

	private function makeTechnical(array $event): array
	{
		return [
			'kind' => self::KIND_TECHNICAL,
			'event' => $event,
			'duration' => null,
			'observedDuration' => null,
			'auto' => null,
			'timestamp' => $event['timestamp'],
			'endTimestamp' => $event['timestamp'],
		];
	}

	/**
	 * @param array<int, array> $items
	 * @return array<int, array>
	 */
	private function mergeManualWithStays(array $items): array
	{
		$technical = array_values(array_filter($items, static fn (array $it): bool => $it['kind'] === self::KIND_TECHNICAL));

		$manualKeys = [];
		$stayKeys = [];
		foreach ($items as $key => $item)
		{
			if ($item['kind'] === self::KIND_MANUAL)
			{
				$manualKeys[] = $key;
			}
			elseif ($item['kind'] === self::KIND_STAY)
			{
				$stayKeys[] = $key;
			}
		}

		usort($manualKeys, static fn (int $a, int $b): int => $items[$a]['timestamp'] <=> $items[$b]['timestamp']);

		$consumedStay = [];
		$mergedManual = [];

		foreach ($manualKeys as $manualKey)
		{
			$manual = $items[$manualKey];

			foreach ($this->sortStayKeysByGap($manual, $stayKeys, $consumedStay, $items) as $stayKey)
			{
				$stay = $items[$stayKey];
				if (!$this->isSameVisit($manual, $stay, $technical))
				{
					continue;
				}

				$consumedStay[$stayKey] = true;
				$mergedManual[$manualKey] = $stayKey;
				break;
			}
		}

		$result = [];
		foreach ($items as $key => $item)
		{
			if ($item['kind'] === self::KIND_STAY && isset($consumedStay[$key]))
			{
				continue;
			}

			if ($item['kind'] === self::KIND_MANUAL && isset($mergedManual[$key]))
			{
				$result[] = $this->makeMerged($item, $items[$mergedManual[$key]]);
				continue;
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Candidates are ordered by the time gap, so the closest stay is still preferred, but a stay
	 * rejected by isSameVisit() no longer blocks the remaining ones.
	 *
	 * @param array<int, int> $stayKeys
	 * @param array<int, bool> $consumedStay
	 * @param array<int, array> $items
	 * @return array<int, int>
	 */
	private function sortStayKeysByGap(array $manual, array $stayKeys, array $consumedStay, array $items): array
	{
		$candidates = [];
		foreach ($stayKeys as $stayKey)
		{
			if (!isset($consumedStay[$stayKey]))
			{
				$candidates[] = $stayKey;
			}
		}

		usort(
			$candidates,
			fn (int $a, int $b): int => $this->timeGap($manual, $items[$a]) <=> $this->timeGap($manual, $items[$b]),
		);

		return $candidates;
	}

	private function timeGap(array $manual, array $stay): int
	{
		[$low, $high] = $this->gapInterval($manual, $stay);

		return max(0, $high - $low);
	}

	/**
	 * The span the manual check-in and the stay do not share. A manual check-in taken inside the stay
	 * gives an empty span, so the bounds come back in reverse order.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function gapInterval(array $manual, array $stay): array
	{
		if ($manual['timestamp'] <= $stay['timestamp'])
		{
			return [$manual['timestamp'], $stay['timestamp']];
		}

		return [$stay['endTimestamp'], $manual['timestamp']];
	}

	/**
	 * Whether the manual check-in and the stay are one visit. Two rules walk the technical points of the
	 * gap together: the place - every point stands inside STOP_RADIUS_METERS, or the track left and came
	 * back; the presence - no silence longer than MERGE_UNSEEN_GAP_MAX_SEC hides a visit of its own.
	 *
	 * @param array<int, array> $technical points of the day ordered by time, see ensureOrderedByTime()
	 */
	private function isSameVisit(array $manual, array $stay, array $technical): bool
	{
		if (!$this->isNear($manual['event'], $stay['event']))
		{
			return false;
		}

		[$low, $high] = $this->gapInterval($manual, $stay);
		$center = $this->stopCenter($manual['event'], $stay['event']);
		$isSilenceLimited = $high - $low > self::MERGE_UNSEEN_GAP_MAX_SEC;
		if ($center === null && !$isSilenceLimited)
		{
			return true;
		}

		$lastConfirmed = $low;
		$pointCount = count($technical);
		for ($index = $this->firstPointAfter($technical, $low); $index < $pointCount; $index++)
		{
			$point = $technical[$index]['event'];
			if ($point['timestamp'] >= $high)
			{
				break;
			}

			if ($point['latitude'] === null || $point['longitude'] === null)
			{
				continue;
			}

			if ($center !== null && !$this->isInsideStop($center, $point))
			{
				return false;
			}

			if ($isSilenceLimited && $point['timestamp'] - $lastConfirmed > self::MERGE_UNSEEN_GAP_MAX_SEC)
			{
				return false;
			}

			$lastConfirmed = $point['timestamp'];
		}

		return !$isSilenceLimited || $high - $lastConfirmed <= self::MERGE_UNSEEN_GAP_MAX_SEC;
	}

	/**
	 * The first technical point later than the timestamp, found by halving the ordered points instead of
	 * walking the whole day for every pair the merge weighs.
	 *
	 * @param array<int, array> $technical points of the day ordered by time
	 */
	private function firstPointAfter(array $technical, int $timestamp): int
	{
		$low = 0;
		$high = count($technical);

		while ($low < $high)
		{
			$middle = intdiv($low + $high, 2);
			if ($technical[$middle]['event']['timestamp'] <= $timestamp)
			{
				$low = $middle + 1;
			}
			else
			{
				$high = $middle;
			}
		}

		return $low;
	}

	/**
	 * @param array{0: float, 1: float} $center
	 */
	private function isInsideStop(array $center, array $point): bool
	{
		$distance = $this->flatDistanceInMeters($center[0], $center[1], $point['latitude'], $point['longitude']);

		return $distance <= self::STOP_RADIUS_METERS;
	}

	private function isNear(array $manual, array $stay): bool
	{
		$hasCoords =
			$manual['latitude'] !== null && $manual['longitude'] !== null
			&& $stay['latitude'] !== null && $stay['longitude'] !== null;

		if ($hasCoords)
		{
			$distance = $this->flatDistanceInMeters($manual['latitude'], $manual['longitude'], $stay['latitude'], $stay['longitude']);
			if ($distance <= self::MERGE_RADIUS_METERS)
			{
				return true;
			}
		}

		return $manual['address'] !== '' && $manual['address'] === $stay['address'];
	}

	/**
	 * @return ?array{0: float, 1: float}
	 */
	private function stopCenter(array $manual, array $stay): ?array
	{
		if ($stay['latitude'] !== null && $stay['longitude'] !== null)
		{
			return [$stay['latitude'], $stay['longitude']];
		}

		if ($manual['latitude'] !== null && $manual['longitude'] !== null)
		{
			return [$manual['latitude'], $manual['longitude']];
		}

		return null;
	}

	private function makeMerged(array $manual, array $stay): array
	{
		$auto = $stay['event'];

		return [
			'kind' => self::KIND_MERGED,
			'event' => $manual['event'],
			'duration' => $stay['duration'],
			// The manual check-in takes the span of the stay whole, so it takes the evidence for that span
			// on the same terms: both are measured from the arrival the stay opened with.
			'observedDuration' => $stay['observedDuration'],
			'auto' => [
				'id' => $auto['id'],
				'timestamp' => $auto['timestamp'],
				'latitude' => $auto['latitude'],
				'longitude' => $auto['longitude'],
				'address' => $auto['address'],
			],
			'timestamp' => min($manual['timestamp'], $stay['timestamp']),
			'endTimestamp' => max($manual['timestamp'], $stay['endTimestamp']),
		];
	}

	/**
	 * @param array<int, array> $items
	 * @return array<int, array{kind: string, event: array, duration: ?int, observedDuration: ?int, auto: ?array, timestamp: int, endTimestamp: int}>
	 */
	private function filterTechnical(array $items): array
	{
		$markers = array_values(array_filter($items, static fn (array $it): bool => $it['kind'] !== self::KIND_TECHNICAL));

		usort(
			$markers,
			static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp'] ?: $a['event']['id'] <=> $b['event']['id'],
		);

		return array_map(
			static fn (array $it): array => [
				'kind' => $it['kind'],
				'event' => $it['event'],
				'duration' => $it['duration'],
				'observedDuration' => $it['observedDuration'],
				'auto' => $it['auto'],
				'timestamp' => $it['timestamp'],
				'endTimestamp' => $it['endTimestamp'],
			],
			$markers,
		);
	}

	protected function flatDistanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
	{
		$avgLat = deg2rad(($lat1 + $lat2) / 2);
		$dx = deg2rad($lon2 - $lon1) * cos($avgLat);
		$dy = deg2rad($lat2 - $lat1);

		return self::EARTH_RADIUS_METERS * sqrt($dx * $dx + $dy * $dy);
	}
}
