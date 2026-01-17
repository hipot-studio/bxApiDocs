<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

class ChecksumService
{
	public function calculateChecksum(string $value, string $algorithm = 'sha256'): string
	{
		return hash($algorithm, $value);
	}
}
