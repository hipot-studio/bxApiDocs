<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Config;

class ConvertConfig
{
	public function __construct(
		public readonly bool $withReplication = false,
	)
	{
	}
}
