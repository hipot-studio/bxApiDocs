<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

use Bitrix\Main\Config\Option;

final class PairingSettings
{
	protected const DEFAULT_MAX_TTL_SECONDS = 604800;
	public const MIN_TTL_SECONDS = 300;
	public const MAX_TTL_SECONDS = 31536000;

	public function getMaxTtlSeconds(): int
	{
		$value = (int)Option::get('vibecodeconnector', 'pairing_max_ttl_seconds', (string)self::DEFAULT_MAX_TTL_SECONDS);

		return $this->clamp($value);
	}

	public function setMaxTtlSeconds(int $seconds): void
	{
		Option::set('vibecodeconnector', 'pairing_max_ttl_seconds', (string)$this->clamp($seconds));
	}

	public function computeExpiresAt(int $fetchedAt, int $publicKeyTtl): int
	{
		$localMax = $this->getMaxTtlSeconds();
		$effective = $publicKeyTtl > 0 && $publicKeyTtl < $localMax ? $publicKeyTtl : $localMax;

		return $fetchedAt + $effective;
	}

	private function clamp(int $seconds): int
	{
		if ($seconds < self::MIN_TTL_SECONDS)
		{
			return self::MIN_TTL_SECONDS;
		}

		if ($seconds > self::MAX_TTL_SECONDS)
		{
			return self::MAX_TTL_SECONDS;
		}

		return $seconds;
	}
}
