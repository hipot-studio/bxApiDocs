<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Copy\Config;

class CopyConfig
{
	public function __construct(
		public readonly int $userId,
	)
	{

	}
}
