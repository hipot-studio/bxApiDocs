<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Mail\Internals\MailMessageMarkTable;

enum ClassificationLabel: string
{
	case Urgent = 'URGENT';
	case Risky = 'RISKY';
	case Lost = 'LOST';

	public function markCode(): int
	{
		return match ($this)
		{
			self::Urgent => MailMessageMarkTable::CODE_CLASSIFICATION_URGENT,
			self::Risky => MailMessageMarkTable::CODE_CLASSIFICATION_RISKY,
			self::Lost => MailMessageMarkTable::CODE_CLASSIFICATION_LOST,
		};
	}

	/**
	 * @return int[]
	 */
	public static function allMarkCodes(): array
	{
		return array_map(static fn (self $case): int => $case->markCode(), self::cases());
	}
}
