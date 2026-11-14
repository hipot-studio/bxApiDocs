<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use InvalidArgumentException;

final class AuditResult
{
	public const SUCCESS = 'success';

	private const KNOWN_RESULTS = [
		self::SUCCESS => true,
	];

	private function __construct()
	{
	}

	public static function assertKnown(string $result): void
	{
		if (!isset(self::KNOWN_RESULTS[$result]))
		{
			throw new InvalidArgumentException('Unknown audit result.');
		}
	}
}
