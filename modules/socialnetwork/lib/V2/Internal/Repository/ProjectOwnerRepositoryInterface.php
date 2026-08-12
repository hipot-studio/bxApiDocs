<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Owner\ProjectOwnerState;

interface ProjectOwnerRepositoryInterface
{
	public function getOwnerState(int $projectId): ?ProjectOwnerState;

	public function findReplacementOwnerId(int $projectId, int $currentOwnerId): ?int;
}
