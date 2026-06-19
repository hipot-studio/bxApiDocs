<?php
namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Main\LoaderException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

class WorkDayService
{
	/** @var ?\CTimeManUser */
	private static $timeManUser = null;

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	public static function isAvailable(): bool
	{
		return \CBXFeatures::IsFeatureEnabled('timeman')
			&& \CModule::IncludeModule('timeman')
			&& \CTimeMan::CanUse();
	}

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
			'record' => $record?->toArray(),
		];
	}

	public static function isDayClosed(int $userId, int $timestamp, int $timezoneOffset): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		$localDate = date('Y-m-d', $timestamp + $timezoneOffset);
		$todayLocalDate = date('Y-m-d', time() + $timezoneOffset);

		if ($localDate === $todayLocalDate)
		{
			$record = (new RecordProvider())->getCurrentRecord($userId);

			return $record?->state->status->value === 'closed';
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
				return true;
			}
		}

		return false;
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
}