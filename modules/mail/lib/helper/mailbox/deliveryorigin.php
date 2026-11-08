<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

final class DeliveryOrigin
{
	public const LIVE = 'live';
	public const BACKFILL = 'backfill';
	public const REPAIR = 'repair';
	public const MIGRATION = 'migration';
	public const UNKNOWN = 'unknown';

	private const VALUES = [
		self::LIVE,
		self::BACKFILL,
		self::REPAIR,
		self::MIGRATION,
		self::UNKNOWN,
	];

	public static function isValid(string $origin): bool
	{
		return in_array($origin, self::VALUES, true);
	}
}
