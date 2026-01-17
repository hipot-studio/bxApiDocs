<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Flow;

class FlowParams
{
	public function __construct(
		public readonly int $flowId,
		public readonly int $userId,
		public readonly bool $checkAccess = true,
	)
	{

	}
}
