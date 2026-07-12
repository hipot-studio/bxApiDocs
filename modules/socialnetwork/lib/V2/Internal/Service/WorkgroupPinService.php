<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupPinRepositoryInterface;

class WorkgroupPinService
{
	public function __construct(
		private readonly WorkgroupPinRepositoryInterface $workgroupPinRepository,
	)
	{
	}

	/**
	 * @param int[] $groupIds
	 * @return array<int, bool>
	 */
	public function getPinFlags(array $groupIds, int $userId, WorkgroupPinMode $mode): array
	{
		return $this->workgroupPinRepository->getPinFlags(
			groupIds: $groupIds,
			userId: $userId,
			mode: $mode,
		);
	}
}
