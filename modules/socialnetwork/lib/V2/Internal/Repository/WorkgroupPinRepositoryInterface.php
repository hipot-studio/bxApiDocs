<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;

interface WorkgroupPinRepositoryInterface
{
	/**
	 * @param int[] $groupIds
	 * @return array<int, bool>
	 */
	public function getPinFlags(array $groupIds, int $userId, WorkgroupPinMode $mode): array;
}
