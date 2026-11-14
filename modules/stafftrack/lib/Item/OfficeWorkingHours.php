<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;

class OfficeWorkingHours implements Arrayable
{
	private const MINUTES_IN_HOUR = 60;

	public function __construct(
		public string $from,
		public string $to,
	) {}

	public static function fromMinutes(?int $from, ?int $to): ?self
	{
		if ($from === null || $to === null)
		{
			return null;
		}

		return new self(
			from: self::formatMinutes($from),
			to: self::formatMinutes($to),
		);
	}

	public function toArray(): array
	{
		return [
			'from' => $this->from,
			'to' => $this->to,
		];
	}

	private static function formatMinutes(int $minutes): string
	{
		return sprintf(
			'%02d:%02d',
			intdiv($minutes, self::MINUTES_IN_HOUR),
			$minutes % self::MINUTES_IN_HOUR,
		);
	}
}
