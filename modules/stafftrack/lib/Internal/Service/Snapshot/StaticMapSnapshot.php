<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Snapshot;

final class StaticMapSnapshot
{
	public function __construct(
		public readonly string $content,
		public readonly string $mimeType,
		public readonly string $extension,
	)
	{
	}
}
