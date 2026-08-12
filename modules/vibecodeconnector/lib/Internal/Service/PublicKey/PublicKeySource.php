<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

enum PublicKeySource: string
{
	case STATIC = 'static';
	case MICROSERVICE = 'microservice';

	public static function default(): self
	{
		return self::STATIC;
	}

	public static function tryFromOrDefault(?string $value): self
	{
		if ($value === null || $value === '')
		{
			return self::default();
		}

		return self::tryFrom($value) ?? self::default();
	}
}
