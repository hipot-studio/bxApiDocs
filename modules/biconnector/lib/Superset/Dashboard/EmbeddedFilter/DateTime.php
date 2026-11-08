<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;

class DateTime extends UrlFilter
{
	public const CONFIG_PERIOD_OPTION_NAME = 'bi_superset_dashboard_period';
	public const CONFIG_DATE_START_OPTION_NAME = 'bi_superset_dashboard_default_date_start';
	public const CONFIG_DATE_END_OPTION_NAME = 'bi_superset_dashboard_default_date_end';
	public const CONFIG_INCLUDE_LAST_FILTER_DATE_OPTION_NAME = 'bi_superset_dashboard_default_include_last_filter_date';

	public const PERIOD_RANGE = 'range';

	public const PERIOD_LAST_7 = 'last_7';
	public const PERIOD_LAST_30 = 'last_30';
	public const PERIOD_LAST_90 = 'last_90';
	public const PERIOD_LAST_180 = 'last_180';
	public const PERIOD_LAST_365 = 'last_365';
	public const PERIOD_CURRENT_MONTH = 'current_month';
	public const PERIOD_CURRENT_WEEK = 'current_week';
	public const PERIOD_CURRENT_YEAR = 'current_year';
	public const PERIOD_PREVIOUS_WEEK = 'previous_week';
	public const PERIOD_PREVIOUS_MONTH = 'previous_month';
	public const PERIOD_PREVIOUS_YEAR = 'previous_year';

	public const PERIOD_DEFAULT = 'default';
	public const PERIOD_NONE = 'none';

	/**
	 * Superset time_range tokens matching the calendar periods of the global BI setting.
	 * The backend dictionary (superset/utils/date_parser.py, TIME_RANGE_MAPPINGS) knows several spellings;
	 * these are the ones the embedded date control of our Superset build names as a period.
	 * Verify on a stand before changing them: the deployed build is what decides, not the source checkout.
	 */
	private const SUPERSET_TIME_RANGE_TOKENS = [
		self::PERIOD_CURRENT_WEEK => 'current calendar week',
		self::PERIOD_CURRENT_MONTH => 'current calendar month',
		self::PERIOD_CURRENT_YEAR => 'current calendar year',
		self::PERIOD_PREVIOUS_WEEK => 'previous calendar week',
		self::PERIOD_PREVIOUS_MONTH => 'previous calendar month',
		self::PERIOD_PREVIOUS_YEAR => 'previous calendar year',
	];

	private string $period;
	private Date $from;
	private Date $to;

	public function __construct(Dashboard $dashboard, ?string $filterId = null)
	{
		parent::__construct($dashboard, $filterId);

		$this->period = self::getDefaultPeriod();

		if ($this->isRange())
		{
			$this->from = self::getDefaultDateStart();
			$this->to = self::getDefaultDateEnd();
		}
		else
		{
			$this->initCalendarPeriod();
		}
	}

	public static function getFilterType(): string
	{
		return 'filter_time';
	}

	/**
	 * Tells whether the dashboard itself resolves the period: its author has set a
	 * default value for the time filter in the Superset builder. Such a dashboard is
	 * left alone — neither the embedded url ({@see NativeFilterBuilder}) nor the assistant
	 * ({@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters})
	 * imposes the period of the global BI setting on it.
	 *
	 * @param array $nativeFilterConfig Native filter config of the dashboard, as Superset returns it.
	 */
	public static function isResolvedByDashboard(array $nativeFilterConfig): bool
	{
		foreach ($nativeFilterConfig as $filterConfig)
		{
			if (
				is_array($filterConfig)
				&& ($filterConfig['filterType'] ?? '') === self::getFilterType()
				&& self::hasConfiguredDefault($filterConfig)
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The same question about a single filter of the config.
	 * The config comes from Superset, so scalars and missing keys must not break the check.
	 */
	public static function hasConfiguredDefault(array $filterConfig): bool
	{
		return !empty($filterConfig['defaultDataMask']['filterState']['value']);
	}

	private function isRange(): bool
	{
		return $this->period === self::PERIOD_RANGE;
	}

	/**
	 * Fills the bounds of a calendar period: current or previous week, month, year.
	 */
	private function initCalendarPeriod(): void
	{
		$this->from = new Date();
		$this->to = new Date();

		match ($this->period)
		{
			self::PERIOD_CURRENT_WEEK => $this->initWeekBounds(0),
			self::PERIOD_PREVIOUS_WEEK => $this->initWeekBounds(-1),
			self::PERIOD_PREVIOUS_MONTH => $this->initMonthBounds(-1),
			self::PERIOD_CURRENT_YEAR => $this->initYearBounds(0),
			self::PERIOD_PREVIOUS_YEAR => $this->initYearBounds(-1),
			default => $this->initMonthBounds(0),
		};

		$this->to->add('+1 day');
	}

	private function initWeekBounds(int $weekShift): void
	{
		$weekDay = (int)$this->from->format('w');
		$weekDay = $weekDay === 0 ? 7 : $weekDay;
		$dayShift = $weekShift * 7;

		$this->from->add(sprintf('%+d days', $dayShift - $weekDay + 1));
		$this->to->add(sprintf('%+d days', $dayShift + 7 - $weekDay));
	}

	private function initMonthBounds(int $monthShift): void
	{
		$year = (int)$this->from->format('Y');
		$month = (int)$this->from->format('m');

		$this->from->setDate($year, $month, 1)->add(sprintf('%+d months', $monthShift));
		$this->to->setDate($year, $month, 1)->add(sprintf('%+d months', $monthShift + 1))->add('-1 day');
	}

	private function initYearBounds(int $yearShift): void
	{
		$year = (int)$this->from->format('Y') + $yearShift;

		$this->from->setDate($year, 1, 1);
		$this->to->setDate($year, 12, 31);
	}

	public function getFormatted(): string
	{
		$urlTemplateFilter = '
			#FILTER_ID#:(
				extraFormData:(
					time_range:\'#TIME_RANGE#\'
				),
				filterState:(
					value:\'#TIME_RANGE#\'
				),
				id:#FILTER_ID#,
				ownState:()
			)';

		return strtr(
			$urlTemplateFilter,
			[
				'#FILTER_ID#' => $this->getCode(),
				// the filter is inlined into the iframe url query, where a space is a plus
				'#TIME_RANGE#' => str_replace(' ', '+', $this->getTimeRange()),
			]
		);
	}

	/**
	 * A calendar period travels to Superset as its own token instead of the computed pair of dates:
	 * only a token makes the date control show the named period, a pair of dates reads as a custom range.
	 * The bounds stay computed on the portal - they feed the assistant.
	 */
	private function getTimeRange(): string
	{
		$token = self::SUPERSET_TIME_RANGE_TOKENS[$this->period] ?? null;
		if ($token !== null)
		{
			return $token;
		}

		$to = clone($this->to);
		// add one day to include the end date for range filter
		if ($this->isRange())
		{
			$to->add('+1 day');
		}

		return sprintf('%s : %s', $this->from->format('Y-m-d'), $to->format('Y-m-d'));
	}

	public function getPeriod(): string
	{
		return $this->period;
	}

	public static function getPeriodName(string $name): string
	{
		$name = strtoupper($name);

		return Loc::getMessage("BICONNECTOR_SUPERSET_EMBEDDED_FILTER_RANGE_{$name}") ?? '';
	}

	public function getDateEnd(): Date
	{
		return $this->to;
	}

	public function getDateStart(): Date
	{
		return $this->from;
	}

	/**
	 * @deprecated last date is always included in the date filter
	 */
	public function needIncludeLastFilterDate(): bool
	{
		return true;
	}

	public static function getDefaultPeriod(): string
	{
		$defaultValue = self::PERIOD_CURRENT_MONTH;
		$value = Option::get('biconnector', self::CONFIG_PERIOD_OPTION_NAME, $defaultValue);
		if (self::isAvailablePeriod($value))
		{
			return $value;
		}

		return $defaultValue;
	}

	public static function getDefaultDateEnd(): Date
	{
		$defaultValue = (new Date())->add('-1 day');
		$option = Option::get('biconnector', self::CONFIG_DATE_END_OPTION_NAME, $defaultValue->toString());
		$value = new Date($option);

		$today = new Date();
		if ($value->getTimestamp() > $today->getTimestamp())
		{
			return $defaultValue;
		}

		return $value;
	}

	public static function getDefaultDateStart(): Date
	{
		$dateEnd = self::getDefaultDateEnd();
		$defaultValue = clone($dateEnd);
		$defaultValue->add('-1 year');
		$option = Option::get('biconnector', self::CONFIG_DATE_START_OPTION_NAME, $defaultValue->toString());
		$value = new Date($option);
		if ($value->getTimestamp() > $dateEnd->getTimestamp())
		{
			return $defaultValue;
		}

		return $value;
	}

	/**
	 * @deprecated last date is always included in the date filter
	 */
	public static function needIncludeDefaultLastFilterDate(): bool
	{
		return true;
	}

	public static function isAvailablePeriod(string $period): bool
	{
		return in_array(
			$period,
			[
				self::PERIOD_RANGE,
				self::PERIOD_CURRENT_WEEK,
				self::PERIOD_CURRENT_MONTH,
				self::PERIOD_CURRENT_YEAR,
				self::PERIOD_PREVIOUS_WEEK,
				self::PERIOD_PREVIOUS_MONTH,
				self::PERIOD_PREVIOUS_YEAR,
			],
			true
		);
	}
}
