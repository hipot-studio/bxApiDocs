<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Internal\Geo\Distance;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;

/**
 * Whether the zone of an office about to be saved runs into the zone of one already in the directory,
 * and which of the ones it runs into stands nearest (ALG-01). Nothing else is decided here: the right
 * of the caller, the transaction of the write and the shape of the refusal belong to the command above.
 *
 * The check is two steps and the split is the point of it. The storage sieves by latitude alone, and
 * widely - the band is the zone of the candidate plus the widest zone the directory holds - because a
 * band along one column is all an index can do about a circle. The verdict is then counted over that
 * answer by {@see Distance}, the one piece of geo arithmetic of the module: it is what crosses the
 * antimeridian the short way round, and it alone decides.
 *
 * The widest zone is read from the rows and not taken from the set the form offers: the column holds
 * whatever was written to it over the life of a portal, and a band cut by the set of today would
 * step over an office of a wider zone written yesterday without a word.
 */
class GeoZoneOverlapService
{
	/** Meters the band of latitude is widened by, against the rounding of its own arithmetic. */
	private const SEARCH_MARGIN = 1;

	private OfficeRepository $officeRepository;

	public function __construct(?OfficeRepository $officeRepository = null)
	{
		$this->officeRepository = $officeRepository ?? new OfficeRepository();
	}

	/**
	 * The nearest office of the directory whose zone the candidate runs into, or nothing when it runs
	 * into none. Zones that merely touch are not overlapping: they meet at a point and share no ground.
	 *
	 * A directory holding no office is answered before anything is read from it: with no zone to clash
	 * with there is nothing to look for, and the band is not asked for at all.
	 *
	 * @param int $radius zone of the candidate, in meters
	 * @param int|null $excludeOfficeId office left out of the check - the one being edited, when an edit
	 *                 is what the caller is doing
	 * @return array{officeId: int, title: string, distance: int}|null the overlapped office and the
	 *         distance to it in whole meters
	 */
	public function findNearestOverlap(
		float $latitude,
		float $longitude,
		int $radius,
		?int $excludeOfficeId = null,
	): ?array
	{
		$maxRadius = $this->officeRepository->getMaxGeoRadius();

		if ($maxRadius === null)
		{
			return null;
		}

		$band = $this->officeRepository->findByLatitudeBand(
			$latitude,
			$radius + $maxRadius + self::SEARCH_MARGIN,
		);

		$nearest = null;
		$nearestDistance = null;

		foreach ($band as $office)
		{
			$officeId = (int)$office['ID'];

			if ($officeId === $excludeOfficeId)
			{
				continue;
			}

			$distance = Distance::inMeters(
				$latitude,
				$longitude,
				(float)$office['LATITUDE'],
				(float)$office['LONGITUDE'],
			);

			if ($distance >= $radius + (int)$office['GEO_RADIUS'])
			{
				continue;
			}

			// the band comes ordered by identifier, so of two offices equally far away the first one
			// seen is the one with the smaller identifier and a later equal may not take its place
			if ($nearestDistance !== null && $distance >= $nearestDistance)
			{
				continue;
			}

			$nearestDistance = $distance;
			$nearest = [
				'officeId' => $officeId,
				'title' => (string)$office['TITLE'],
				'distance' => (int)round($distance),
			];
		}

		return $nearest;
	}
}
