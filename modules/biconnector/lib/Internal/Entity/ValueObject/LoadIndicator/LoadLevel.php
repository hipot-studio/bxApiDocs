<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator;

enum LoadLevel: string
{
	case Low = 'LOW';
	case Medium = 'MEDIUM';
	case High = 'HIGH';

	public function sortOrder(): int
	{
		return match ($this)
		{
			self::High => 3,
			self::Medium => 2,
			self::Low => 1,
		};
	}
}
