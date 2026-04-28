<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Trigger;

use Bitrix\Main\Type\DateTime;

class SyncAgentData
{
	public function __construct(
		public ?int $id = null,
		public ?DateTime $nextRunAt = null,
	)
	{
	}
}
