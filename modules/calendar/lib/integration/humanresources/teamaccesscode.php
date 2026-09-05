<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\HumanResources;

/**
 * Single source of truth for the humanresources team attendee access code (SNT<id>).
 * Strict form only: the recursive SNTR<id> code is intentionally not matched here (CODE-01).
 */
final class TeamAccessCode
{
	public const PREFIX = 'SNT';

	public static function fromNodeId(int $nodeId): string
	{
		return self::PREFIX . $nodeId;
	}

	public static function extractNodeId(string $code): ?int
	{
		if (preg_match('/^' . self::PREFIX . '([0-9]+)$/', $code, $match) === 1)
		{
			return (int)$match[1];
		}

		return null;
	}
}
