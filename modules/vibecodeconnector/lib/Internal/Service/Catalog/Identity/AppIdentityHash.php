<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Identity;

final class AppIdentityHash
{
	public const LENGTH = 64;

	public static function calculate(?string $pairingIss, ?string $externalId): ?string
	{
		if ($externalId === null || $externalId === '')
		{
			return null;
		}

		$payload = $pairingIss === null
			? "\x00" . self::frame($externalId)
			: "\x01" . self::frame($pairingIss) . self::frame($externalId);

		return hash('sha256', $payload);
	}

	private static function frame(string $value): string
	{
		return pack('N', strlen($value)) . $value;
	}
}
