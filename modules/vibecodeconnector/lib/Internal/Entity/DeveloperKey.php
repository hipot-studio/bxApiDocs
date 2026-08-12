<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity;

final class DeveloperKey
{
	public function __construct(
		public readonly int $userId,
		public readonly string $url,
	)
	{
	}
}
