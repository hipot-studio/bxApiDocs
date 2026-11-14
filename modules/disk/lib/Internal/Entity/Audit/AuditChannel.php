<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use InvalidArgumentException;

final class AuditChannel
{
	public const WEB = 'web';
	public const MOBILE = 'mobile';
	public const DESKTOP = 'desktop';
	public const API = 'api';
	public const SYSTEM = 'system';
	public const BIZPROC = 'bizproc';

	private const KNOWN_CHANNELS = [
		self::WEB => true,
		self::MOBILE => true,
		self::DESKTOP => true,
		self::API => true,
		self::SYSTEM => true,
		self::BIZPROC => true,
	];

	private function __construct()
	{
	}

	public static function assertKnown(string $channel): void
	{
		if (!isset(self::KNOWN_CHANNELS[$channel]))
		{
			throw new InvalidArgumentException('Unknown audit channel.');
		}
	}
}
