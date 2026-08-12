<?php

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Main\Loader;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Helper\DateHelper;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Bitrix\Intranet\Settings\Tools\ToolsManager;

class WorkDayService extends BaseService
{
	/** @var ?\CTimeManUser */
	private static $timeManUser = null;

	public static function getWorkTime(int $userId): array
	{
		if (!self::isAvailable())
		{
			return [
				'isTimeManIntegrationEnabled' => false,
				'record' => [],
			];
		}

		$record = (new RecordProvider())->getCurrentRecord($userId);

		return [
			'isTimeManIntegrationEnabled' => static::shouldStartWorkDay(),
			'isNotWorkingDay' => self::isNotWorkingDay($userId),
			'record' => $record?->toArray(),
		];
	}

	public static function isNotWorkingDay(int $userId): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		try
		{
			$dateHelper = DateHelper::getInstance();

			return $dateHelper->isNotWorkingDay($dateHelper->getOffsetDate($userId));
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public static function isDayClosed(int $userId, int $timestamp, int $timezoneOffset): bool
	{
		return self::getDayCloseTimestamp($userId, $timestamp, $timezoneOffset) !== null;
	}

	public static function getDayCloseTimestamp(int $userId, int $timestamp, int $timezoneOffset): ?int
	{
		if (!self::isAvailable())
		{
			return null;
		}

		$localDate = date('Y-m-d', $timestamp + $timezoneOffset);
		$todayLocalDate = date('Y-m-d', time() + $timezoneOffset);

		if ($localDate === $todayLocalDate)
		{
			$record = (new RecordProvider())->getCurrentRecord($userId);

			if ($record?->state->status->value !== 'closed')
			{
				return null;
			}

			return $record?->endTime;
		}

		$windowStart = strtotime($localDate . ' 00:00:00 UTC') - $timezoneOffset;
		$windowEnd = $windowStart + 86400;

		$recordCollection = (new RecordProvider())->getRecords(
			new ListParams(
				pager: new Pager(limit: 100, offset: 0),
				filter: new Filter(
					userId: $userId,
					dateFrom: $windowStart,
					dateTo: $windowEnd - 1,
				),
			)
		);

		foreach ($recordCollection as $record)
		{
			$endTime = $record->endTime;
			if (
				$record->state->status->value === 'closed'
				&& $endTime !== null
				&& $endTime >= $windowStart
				&& $endTime < $windowEnd
			)
			{
				return $endTime;
			}
		}

		return null;
	}

	public static function shouldStartWorkDay(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return self::isTimeManIntegrationEnabled();
	}

	public static function changeTimemanIntegrationOption(bool $optionValue): void
	{
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

		OptionService::getInstance()->save(
			$userId,
			Option::TIMEMAN_INTEGRATION_ENABLED,
			$optionValue ? 'Y' : 'N',
		);

		OptionProvider::getInstance()->invalidateCache($userId);
	}

	public static function startWorkDay(): void
	{
		if (!self::shouldStartWorkDay())
		{
			return;
		}

		$timeMan = self::getTimeManUser();
		if (!$timeMan?->isDayOpen())
		{
			$timeMan?->openDay(false, '', [
				'DEVICE' => ScheduleTable::ALLOWED_DEVICES_MOBILE,
			]);
		}
	}

	public static function enableWorkShiftOption(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->enableShifts();
		}
	}

	public static function isDayStartCheckInEnabled(): bool
	{
		return self::isAvailable() && (new SettingsProvider())->isDayStartCheckInEnabled();
	}

	public static function isDayStartCheckInEnabledInSettings(): bool
	{
		return self::isAvailable() && (new SettingsProvider())->isDayStartCheckInEnabledInSettings();
	}

	public static function canChangeDayStartCheckIn(): bool
	{
		return self::isAvailable() && (new SettingsProvider())->canChangeDayStartCheckIn();
	}

	public static function enableDayStartCheckIn(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->enableDayStartCheckIn();
		}
	}

	public static function disableDayStartCheckIn(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->disableDayStartCheckIn();
		}
	}

	private static function getTimeManUser(): ?\CTimeManUser
	{
		if (self::$timeManUser === null)
		{
			self::$timeManUser = \CTimeManUser::instance();
		}

		return self::$timeManUser;
	}

	private static function isTimeManIntegrationEnabled(): bool
	{
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

		$value = OptionProvider::getInstance()
			->getOption($userId, Option::TIMEMAN_INTEGRATION_ENABLED)
			?->getValue()
		;

		return !$value || $value === 'Y';
	}

	public static function isWorkTimeToolAvailable(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		return (ToolsManager::getInstance())->checkAvailabilityByToolId('worktime');
	}
}
