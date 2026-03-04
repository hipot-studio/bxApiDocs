<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Policy;

use Bitrix\Main\Type\DateTime;

class DeadlinePolicyChangeContext
{
	public function __construct(
		public readonly bool $isAllowed = false,
		public readonly bool $isDateExceedsLimit = false,
		public readonly ?DateTime $dateLimit = null,
	)
	{
	}
}
