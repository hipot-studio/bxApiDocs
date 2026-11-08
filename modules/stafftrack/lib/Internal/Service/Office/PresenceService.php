<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Dictionary\OfficePresence;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Entity\StayEvent;
use Bitrix\StaffTrack\Internal\Geo\Distance;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficePresenceRepository;
use Bitrix\StaffTrack\Public\Command\CheckInDto;

/**
 * Presence in the office of assignment, written by the points of an employee and read back as a status.
 * The two entrances stand on opposite sides of the same row and share nothing but it: {@see applyPoints}
 * is called by the paths of acceptance, {@see getStatuses} by the staff of an office card.
 *
 * The write is called from the tail of a command - past the commit of the point, past the lock of the
 * employee and past the master-only mode - so nothing in it may be relied upon by the acceptance itself:
 * a failure comes back as a sign and the caller journals it. The order of its steps is the point of it
 * and not an accident: the flag of the offices stands before every query, and the points with no
 * coordinates are sieved out before the office is read, because a call left with nothing to weigh is
 * known in advance to write nothing, and a path that takes four thousand automatic points per employee
 * per day may not pay a query for it.
 *
 * Neither moment of a row looks at the other, and both are written as a maximum over the whole set rather
 * than off its last element: the order of a batch is held by the client alone, and the client breaks it
 * on purpose where a stay is dated back to the moment it began.
 *
 * The read asks no flag of its own: it is reached from inside the office directory, which is closed by
 * the barrier of its own surface long before this.
 *
 * The third entrance, {@see isSignalAllowed}, answers whether an employee may confirm their presence by a
 * signal of the client at all. It owns that rule because it owns the gates of the write: whoever may be
 * written about by a signal may send one, and nobody else.
 */
class PresenceService
{
	/** How long a confirmation earned inside the zone of an office stands for (AC-028 of the BRIEF). */
	public const CONFIRMATION_WINDOW_SEC = 3600;

	/** Past this much silence nothing is said about a person at all, neither here nor there. */
	public const SILENCE_LIMIT_SEC = 86400;

	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected OfficePresenceRepository $presenceRepository;

	public function __construct()
	{
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->presenceRepository = new OfficePresenceRepository();
	}

	/**
	 * The row of presence of an employee after the points of one call of acceptance (ALG-02).
	 *
	 * The points are the stored ones rather than the accepted ones: a recognised repeat created no row
	 * and says nothing new about where its author was.
	 *
	 * @param CheckInDto[] $points points of one employee, already written by the caller
	 * @param int|null $now moment of the call, server time by default
	 * @return bool whether the storage was left in the state the points asked for; a call with nothing
	 *              to write is a success of its own
	 */
	public function applyPoints(int $userId, array $points, ?int $now = null): bool
	{
		if (!$this->isOfficesFlagEnabled())
		{
			return true;
		}

		$locatedPoints = self::locatedPoints($points);

		if ($locatedPoints === [])
		{
			return true;
		}

		$office = $this->officeEmployeeRepository->getOfficeZoneByUserId($userId);

		if ($office === null)
		{
			return true;
		}

		$serverNow = $now ?? time();

		$lastPointDate = null;
		$inZoneDate = null;

		foreach ($locatedPoints as $point)
		{
			$moment = self::momentOf($point, $serverNow);

			if ($lastPointDate === null || $moment > $lastPointDate)
			{
				$lastPointDate = $moment;
			}

			// the end of a stay never confirms an office, wherever it was earned, so its distance is
			// not measured at all
			if ($point->description === StayEvent::LEAVE->value)
			{
				continue;
			}

			if (!self::isInsideZone($point, $office))
			{
				continue;
			}

			if ($inZoneDate === null || $moment > $inZoneDate)
			{
				$inZoneDate = $moment;
			}
		}

		// no guard on the moment of the last point: the set is not empty and every point of it raises
		// that moment, so a branch for a missing one could never be reached
		return $this->presenceRepository->write(
			$userId,
			$office['id'],
			DateTime::createFromTimestamp($lastPointDate),
			$inZoneDate === null ? null : DateTime::createFromTimestamp($inZoneDate),
		);
	}

	/**
	 * Where a set of employees stands with one named office (ALG-03), in one query whatever the size of
	 * the set: the staff of an office is answered for as a whole page, and asking person by person would
	 * cost a query per person.
	 *
	 * Every employee of the set is answered for, whether a row was found for them or not. Nothing outside
	 * this storage is asked - not the working day, not the absences of the staff, not the settings of the
	 * rollout: presence is what the points of a person said and nothing besides.
	 *
	 * @param int[] $userIds employees to answer for, already picked out by the caller
	 * @param int|null $now moment of the read, server time by default
	 * @return array<int, OfficePresence> user id => where they stand with this office
	 */
	public function getStatuses(int $officeId, array $userIds, ?int $now = null): array
	{
		$rows = $this->presenceRepository->findByUserIds($userIds);
		$serverNow = $now ?? time();

		$statuses = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			$statuses[$userId] = self::statusOf($rows[$userId] ?? null, $officeId, $serverNow);
		}

		return $statuses;
	}

	/**
	 * Whether an employee may confirm their presence by a signal of the client (ALG-02 of the signal).
	 * The conditions are the gates a signal passes on the write, asked from the free ones to the one that
	 * costs a query: the switch of the signal, the flag of the offices and the crypto of the portal are
	 * read out of the settings, and only a portal with the offices on pays the read of the assignment.
	 *
	 * The assignment is read by the same join the write reads it by, so an assignment to an office that
	 * is gone answers "no" here the same as it answers "nothing to write" there.
	 *
	 * Nothing is cached: the client keeps the answer with the rest of the conditions of its cycle.
	 */
	public function isSignalAllowed(int $userId): bool
	{
		if (!$this->isSignalEnabled() || !$this->isOfficesFlagEnabled() || !$this->isGeoEncryptionAvailable())
		{
			return false;
		}

		return $this->officeEmployeeRepository->getOfficeZoneByUserId($userId) !== null;
	}

	/**
	 * Silence is answered first and as one case: a person with no row at all and a person whose last
	 * point is a day old are equally unknown, and neither is called absent - being absent is something
	 * the points of a person have to earn as much as being present is.
	 *
	 * The confirmation is held against the last point rather than against the moment of the read alone,
	 * and the comparison is not strict: a point inside the zone raises both moments to one value, and a
	 * strict one would put the status out at the very moment it was confirmed. The office travels with
	 * the confirmation, so a confirmation earned elsewhere is not counted for this one.
	 *
	 * @param array{OFFICE_ID: int, IN_ZONE_DATE: ?DateTime, LAST_POINT_DATE: DateTime}|null $row
	 */
	private static function statusOf(?array $row, int $officeId, int $serverNow): OfficePresence
	{
		if ($row === null || $serverNow - $row['LAST_POINT_DATE']->getTimestamp() > self::SILENCE_LIMIT_SEC)
		{
			return OfficePresence::UNKNOWN;
		}

		$confirmed = $row['IN_ZONE_DATE'];

		$isConfirmed = (int)$row['OFFICE_ID'] === $officeId
			&& $confirmed !== null
			&& $confirmed->getTimestamp() >= $row['LAST_POINT_DATE']->getTimestamp()
			&& $serverNow - $confirmed->getTimestamp() <= self::CONFIRMATION_WINDOW_SEC
		;

		return $isConfirmed ? OfficePresence::PRESENT : OfficePresence::ABSENT;
	}

	/**
	 * The seam the flag is read through: neither the settings of the portal nor the flags over them are
	 * reachable from a unit test, and the neighbouring {@see OfficesAvailability} leaves the same one.
	 *
	 * The other two thirds of the office gate are not repeated here: the acceptance of a point has
	 * already passed the switch of the check-in and the region of the license in its own prefilters.
	 */
	protected function isOfficesFlagEnabled(): bool
	{
		return Feature::isOfficesFlagEnabled();
	}

	/**
	 * The two other gates of the signal, left at seams of their own for the same reason as the flag: the
	 * switch lives in the options of the portal and the crypto in its configuration.
	 */
	protected function isSignalEnabled(): bool
	{
		return Feature::isOfficePresenceSignalEnabled();
	}

	protected function isGeoEncryptionAvailable(): bool
	{
		return Feature::isGeoEncryptionAvailable();
	}

	/**
	 * The points that say where their author was. Coordinates carry a default of zero and the parsing of
	 * a DTO lets a property equal to its default through, so a point with no location of its own passes
	 * the validation and arrives here. It is not a point somewhere else: nothing was heard about where
	 * its author was, and putting out a confirmation by it would be judging what no data was sent about.
	 *
	 * @param CheckInDto[] $points
	 * @return CheckInDto[]
	 */
	private static function locatedPoints(array $points): array
	{
		return array_filter(
			$points,
			static fn (CheckInDto $point): bool => $point->latitude !== 0.0 || $point->longitude !== 0.0,
		);
	}

	/**
	 * The moment of a point, never ahead of the server clock. The batch path leaves the time of a point
	 * as the client sent it - there it is the key a repeat is recognised by - and the window of
	 * acceptance lets it run up to an hour forward. Written down as it came, such a moment could never be
	 * lowered again: every later point, the end of a stay included, would lose the comparison the storage
	 * makes. The ceiling touches nothing behind it, so a batch that came late stays late.
	 *
	 * A point with no time of its own is stamped with the server one by both write paths before it is
	 * saved, so the fallback below is that same moment rather than a case apart.
	 */
	private static function momentOf(CheckInDto $point, int $serverNow): int
	{
		return min($point->dateCreate?->getTimestamp() ?? $serverNow, $serverNow);
	}

	/**
	 * @param array{latitude: float, longitude: float, geoRadius: int} $office
	 */
	private static function isInsideZone(CheckInDto $point, array $office): bool
	{
		$distance = Distance::inMeters(
			$point->latitude,
			$point->longitude,
			$office['latitude'],
			$office['longitude'],
		);

		return $distance <= (float)$office['geoRadius'];
	}
}
