<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

/**
 * One record of {@see AllFolder}'s canonical source registry (DTO-03).
 *
 * Resolution is uniform and carries no per-record data: a source resolves to the
 * user's member folder whose code equals {@see SourceEntry::$code}
 * ("source code == folder code"). The membership expansion itself is built later
 * through the system-folder registry, not stored here.
 */
final class SourceEntry
{
	public function __construct(
		public readonly string $code,
		public readonly bool $mandatory,
		public readonly bool $inInitialConfig,
	) {}
}
