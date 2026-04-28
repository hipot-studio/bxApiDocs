<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Trigger;

use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType;

class ScheduledData
{
	private const UTC = 'UTC';

	public function __construct(
		public string $startAt,
		public string $timezone,
		public ScheduleType $frequency,
		public int $interval = 1,
		public ?int $byMonth = null,
		public ?int $byMonthDay = null,
		public array $byWeekDay = [],
	)
	{
	}

	public function toArray(): array
	{
		return [
			'START_AT' => $this->startAt,
			'TIMEZONE' => $this->timezone,
			'FREQUENCY' => $this->frequency->value,
			'INTERVAL' => $this->interval,
			'BY_WEEK_DAY' => $this->byWeekDay,
			'BY_MONTH_DAY' => $this->byMonthDay,
			'BY_MONTH' => $this->byMonth,
		];
	}

	public static function fromArray(array $data): static
	{
		$byMonth = $data['BY_MONTH'] ?? null;
		$byMonthDay = $data['BY_MONTH_DAY'] ?? null;

		return new static(
			startAt: (string)($data['START_AT'] ?? ''),
			timezone: (string)($data['TIMEZONE'] ?? self::UTC),
			frequency: ScheduleType::tryFrom((string)($data['FREQUENCY'] ?? ScheduleType::Once->value)) ?? ScheduleType::Once,
			interval: max(1, (int)($data['INTERVAL'] ?? 1)),
			byMonth: isset($byMonth) ? (int)(is_array($byMonth) ? reset($byMonth) : $byMonth) : null,
			byMonthDay: isset($byMonthDay) ? (int)(is_array($byMonthDay) ? reset($byMonthDay) : $byMonthDay) : null,
			byWeekDay: is_array($data['BY_WEEK_DAY'] ?? null) ? $data['BY_WEEK_DAY'] : [],
		);
	}
}