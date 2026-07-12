<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\WorkgroupTagTable;

class ProjectTagRepository implements ProjectTagRepositoryInterface
{
	public function save(int $groupId, array $tagNames): void
	{
		WorkgroupTagTable::set(['groupId' => $groupId, 'tags' => $tagNames]);
	}
}
