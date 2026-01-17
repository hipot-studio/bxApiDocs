<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\Internals;

class PriorityMapper
{
	public function mapToEnum(int $priority): ?Entity\Priority
	{
		return match ($priority) {
			Internals\Task\Priority::LOW => Entity\Priority::Low,
			Internals\Task\Priority::AVERAGE => Entity\Priority::Average,
			Internals\Task\Priority::HIGH => Entity\Priority::High,
			default => null,
		};
	}

	public function mapFromEnum(Entity\Priority $priority): ?int
	{
		return match ($priority) {
			Entity\Priority::Low => Internals\Task\Priority::LOW,
			Entity\Priority::Average => Internals\Task\Priority::AVERAGE,
			Entity\Priority::High => Internals\Task\Priority::HIGH,
			default => null,
		};
	}
}
