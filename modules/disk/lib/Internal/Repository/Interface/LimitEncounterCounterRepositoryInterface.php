<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\Internal\Enum\LimitEncounterType;
use Bitrix\Disk\Internal\Service\ItemsCountResult;

interface LimitEncounterCounterRepositoryInterface
{
	public function incrementUnlessMax(LimitEncounterType $type, int $max): ItemsCountResult;

	public function get(LimitEncounterType $type): ?int;
}
