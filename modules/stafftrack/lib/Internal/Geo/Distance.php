<?php

namespace Bitrix\StaffTrack\Internal\Geo;

/**
 * The distance between two points of the map as an equirectangular approximation: the meridian taken
 * straight and the parallel shortened by the cosine of the latitude between the two.
 *
 * The same arithmetic is written a second time in Public\Services\CheckInMarkerAggregator, and the copy
 * is deliberate: Internal may not reach into Public, and the aggregator is a released surface of another
 * domain. Drifting apart is not a risk - the shape of the Earth is no decision of this module. Neither
 * side carries a threshold of its own: the aggregator has one, presence uses the radius of the office.
 * The one place the copies part ways is the antimeridian, which this one crosses the shorter way round.
 */
class Distance
{
	private const EARTH_RADIUS_METERS = 6_371_000;

	/**
	 * @param float $latitude1 Latitude in degrees (-90 to 90).
	 * @param float $longitude1 Longitude in degrees (-180 to 180).
	 * @param float $latitude2 Latitude in degrees (-90 to 90).
	 * @param float $longitude2 Longitude in degrees (-180 to 180).
	 * @return float
	 */
	public static function inMeters(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
	{
		$averageLatitude = deg2rad(($latitude1 + $latitude2) / 2);
		// The shorter way round: a step across the antimeridian is not a trip round the globe.
		$dLongitude = $longitude2 - $longitude1;
		$dLongitude -= 360 * round($dLongitude / 360);
		$dx = deg2rad($dLongitude) * cos($averageLatitude);
		$dy = deg2rad($latitude2 - $latitude1);

		return self::EARTH_RADIUS_METERS * sqrt($dx * $dx + $dy * $dy);
	}
}
