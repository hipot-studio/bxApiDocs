<?php

namespace Bitrix\StaffTrack\Internal\Geo;

class Geohash
{
	private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';
	private const DEFAULT_PRECISION = 8;

	/**
	 * @param float $latitude Latitude in degrees (-90 to 90).
	 * @param float $longitude Longitude in degrees (-180 to 180).
	 * @param int $precision
	 * @return string
	 */
	public static function encode(float $latitude, float $longitude, int $precision = self::DEFAULT_PRECISION): string
	{
		$latMin = -90.0;
		$latMax = 90.0;
		$lonMin = -180.0;
		$lonMax = 180.0;

		$hash = '';
		$bit = 0;
		$charIndex = 0;
		$isLon = true;

		while (mb_strlen($hash) < $precision)
		{
			if ($isLon)
			{
				$mid = ($lonMin + $lonMax) / 2;
				if ($longitude >= $mid)
				{
					$charIndex = ($charIndex << 1) | 1;
					$lonMin = $mid;
				}
				else
				{
					$charIndex <<= 1;
					$lonMax = $mid;
				}
			}
			else
			{
				$mid = ($latMin + $latMax) / 2;
				if ($latitude >= $mid)
				{
					$charIndex = ($charIndex << 1) | 1;
					$latMin = $mid;
				}
				else
				{
					$charIndex <<= 1;
					$latMax = $mid;
				}
			}

			$isLon = !$isLon;
			$bit++;

			if ($bit === 5)
			{
				$hash .= self::BASE32[$charIndex];
				$bit = 0;
				$charIndex = 0;
			}
		}

		return $hash;
	}

	/**
	 * @return array{lat: float, lon: float, latErr: float, lonErr: float}
	 */
	public static function decode(string $hash): array
	{
		$latMin = -90.0;
		$latMax = 90.0;
		$lonMin = -180.0;
		$lonMax = 180.0;

		$isLon = true;

		for ($i = 0, $len = mb_strlen($hash); $i < $len; $i++)
		{
			$charIndex = mb_strpos(self::BASE32, $hash[$i]);
			if ($charIndex === false)
			{
				break;
			}

			for ($bit = 4; $bit >= 0; $bit--)
			{
				$bitValue = ($charIndex >> $bit) & 1;

				if ($isLon)
				{
					$mid = ($lonMin + $lonMax) / 2;
					if ($bitValue === 1)
					{
						$lonMin = $mid;
					}
					else
					{
						$lonMax = $mid;
					}
				}
				else
				{
					$mid = ($latMin + $latMax) / 2;
					if ($bitValue === 1)
					{
						$latMin = $mid;
					}
					else
					{
						$latMax = $mid;
					}
				}

				$isLon = !$isLon;
			}
		}

		return [
			'lat' => ($latMin + $latMax) / 2,
			'lon' => ($lonMin + $lonMax) / 2,
			'latErr' => ($latMax - $latMin) / 2,
			'lonErr' => ($lonMax - $lonMin) / 2,
		];
	}
}
