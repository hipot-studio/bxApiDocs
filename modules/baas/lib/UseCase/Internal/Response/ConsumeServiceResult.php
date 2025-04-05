<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\Internal\Response;

use Bitrix\Main;

class ConsumeServiceResult extends Main\Result
{
	public function __construct(
		public readonly string $consumptionId,
	)
	{
		parent::__construct();
	}
}
