<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Path;

final class CachePath
{
	public function __construct(
		public readonly string $id,
		public readonly string $dir,
	){}
}
