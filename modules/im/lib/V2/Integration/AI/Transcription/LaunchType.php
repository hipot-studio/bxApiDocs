<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Transcription;

enum LaunchType: string
{
	case Auto = 'auto';
	case Manual = 'manual';

	public static function fromString(?string $value): self
	{
		return match ($value)
		{
			self::Manual->value => self::Manual,
			default => self::Auto,
		};
	}
}
