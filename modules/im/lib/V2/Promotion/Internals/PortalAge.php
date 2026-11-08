<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Promotion\Internals;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

final class PortalAge
{
	private const CUTOFF_DATE_FORMAT = 'Y-m-d';

	/**
	 * A promo of an onboarding kind is meant for the portals that already worked without the feature,
	 * so the date of its release is kept in an option of the module the promo belongs to.
	 */
	public static function isCreatedBeforeCutoffDate(
		string $moduleId,
		string $optionName,
		string $defaultDate = ''
	): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$portalCreateTime = (int)\CBitrix24::getCreateTime();
		if ($portalCreateTime <= 0)
		{
			return false;
		}

		$cutoffDate = self::getCutoffDate($moduleId, $optionName, $defaultDate);

		return $cutoffDate !== null && $portalCreateTime < $cutoffDate->getTimestamp();
	}

	private static function getCutoffDate(string $moduleId, string $optionName, string $defaultDate): ?Date
	{
		$value = trim((string)Option::get($moduleId, $optionName, $defaultDate));
		if (!Date::isCorrect($value, self::CUTOFF_DATE_FORMAT))
		{
			return null;
		}

		$date = new Date($value, self::CUTOFF_DATE_FORMAT);

		// the parsing accepts a value the format only starts with, so the date has to survive a round trip
		return $date->format(self::CUTOFF_DATE_FORMAT) === $value ? $date : null;
	}

	/**
	 * A portal that does not know its own creation date has been working without the feature just
	 * like an old one, so it is counted in instead of being left out by the missing date.
	 */
	public static function isCreatedBeforeCutoffDateOrUnknown(
		string $moduleId,
		string $optionName,
		string $defaultDate = ''
	): bool
	{
		$cutoffDate = self::getCutoffDate($moduleId, $optionName, $defaultDate);
		if ($cutoffDate === null)
		{
			return false;
		}

		$portalCreateTime = self::getPortalCreateTime();

		return $portalCreateTime === null || $portalCreateTime < $cutoffDate->getTimestamp();
	}

	private static function getPortalCreateTime(): ?int
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$portalCreateTime = (int)\CBitrix24::getCreateTime();

		return $portalCreateTime > 0 ? $portalCreateTime : null;
	}
}
