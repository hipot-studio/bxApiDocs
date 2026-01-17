<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Enum\Otp;

enum PromoteMode: string
{
	case Disable = 'disable';
	case Low = 'low';
	case Medium = 'medium';
	case High = 'high';
	case Personal = 'personal';

	public function getWeight(): int
	{
		return match($this) {
			self::Disable => 0,
			self::Personal => 1,
			self::Low => 2,
			self::Medium => 3,
			self::High => 4,
		};
	}

	public function isGreaterThan(self $other): bool
	{
		return $this->getWeight() > $other->getWeight();
	}

	public function isGreaterOrEqual(self $other): bool
	{
		return $this->getWeight() >= $other->getWeight();
	}
}
