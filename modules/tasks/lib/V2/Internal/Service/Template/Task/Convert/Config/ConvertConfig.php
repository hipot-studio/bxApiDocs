<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Config;

class ConvertConfig
{
	public function __construct(
		public readonly int $userId,
	)
	{

	}
}
