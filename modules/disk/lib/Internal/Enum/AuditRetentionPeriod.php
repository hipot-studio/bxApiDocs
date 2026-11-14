<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

enum AuditRetentionPeriod: string
{
	public const DEFAULT_VALUE = 'P6M';

	case OneMonth = 'P1M';
	case ThreeMonths = 'P3M';
	case SixMonths = 'P6M';
	case OneYear = 'P1Y';
	case TwoYears = 'P2Y';
	case ThreeYears = 'P3Y';

	public static function default(): self
	{
		return self::SixMonths;
	}

	public static function isValid(string $value): bool
	{
		return self::tryFrom($value) !== null;
	}

	public static function fromStored(?string $value): self
	{
		return self::tryFrom($value ?? '') ?? self::default();
	}

	public function toDateInterval(): DateInterval
	{
		return new DateInterval($this->value);
	}

	public function subtractFrom(DateTimeInterface $dateTime): DateTimeImmutable
	{
		return DateTimeImmutable::createFromInterface($dateTime)->sub($this->toDateInterval());
	}

	public function isShorterThan(self $period): bool
	{
		return $this->toMonths() < $period->toMonths();
	}

	private function toMonths(): int
	{
		return match ($this)
		{
			self::OneMonth => 1,
			self::ThreeMonths => 3,
			self::SixMonths => 6,
			self::OneYear => 12,
			self::TwoYears => 24,
			self::ThreeYears => 36,
		};
	}
}
