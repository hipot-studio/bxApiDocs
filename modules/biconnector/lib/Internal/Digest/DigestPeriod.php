<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest;

use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Localization\Loc;

/**
 * Periods a digest agent can be set to cover. The list is the single source for both sides that need it:
 * the selector the setup wizard shows and the check the activity runs over what came back, so a period
 * offered in the form is always one the run can resolve.
 *
 * The calendar entries are those of the global BI setting ({@see EmbeddedFilter\DateTime::isAvailablePeriod()}).
 * Two entries are not periods of their own and resolve elsewhere: `Report` leaves the choice to the
 * report in Superset, and `PortalDefault` follows the global BI setting. A custom `range` is
 * deliberately absent from the named presets, as is `none`, which has no bounds to pin at all.
 */
enum DigestPeriod: string
{
	case Report = 'report';
	case PortalDefault = 'default';
	case CurrentWeek = 'current_week';
	case CurrentMonth = 'current_month';
	case CurrentYear = 'current_year';
	case PreviousWeek = 'previous_week';
	case PreviousMonth = 'previous_month';
	case PreviousYear = 'previous_year';

	/**
	 * Rolling periods the list offered before it followed the global BI setting. Agents set up then still
	 * store them, so they are read as the calendar period the global setting was moved to
	 * ({@see \Bitrix\BIConnector\Superset\Updater\Versions\Version14}).
	 */
	private const LEGACY_CODES = [
		'last_7' => self::CurrentWeek,
		'last_30' => self::CurrentMonth,
		'last_90' => self::CurrentMonth,
		'last_180' => self::CurrentYear,
		'last_365' => self::CurrentYear,
	];

	/**
	 * The entry a stored code stands for, a legacy rolling code included.
	 */
	public static function tryFromStored(string $code): ?self
	{
		return self::tryFrom($code) ?? self::LEGACY_CODES[$code] ?? null;
	}

	public function title(): string
	{
		return (string)Loc::getMessage('BI_DIGEST_PERIOD_' . strtoupper($this->value));
	}

	/**
	 * The period code to resolve the bounds by, as biconnector names them. An empty string means the
	 * report decides - the answer for `Report`, and for `PortalDefault` once the portal setting is read.
	 */
	public function toFilterPeriod(): string
	{
		return match ($this)
		{
			self::Report => '',
			self::PortalDefault => EmbeddedFilter\DateTime::getDefaultPeriod(),
			default => $this->value,
		};
	}

	/**
	 * @return array<string, string> Period code => title, in the order the wizard shows them.
	 */
	public static function titles(): array
	{
		$titles = [];
		foreach (self::cases() as $period)
		{
			$titles[$period->value] = $period->title();
		}

		return $titles;
	}
}
