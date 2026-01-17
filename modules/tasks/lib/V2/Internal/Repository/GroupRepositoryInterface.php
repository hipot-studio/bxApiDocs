<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

interface GroupRepositoryInterface
{
	public function getById(int $id): ?Entity\Group;

	public function getMembers(int $id): Entity\UserCollection;

	public function getType(int $id): ?string;

	public function getByIds(array $ids): Entity\GroupCollection;

	/**
	 * Retrieve list of Group IDs by corresponding task IDs.
	 *
	 * @param int[] $taskIds 
	 * @return array<int, int>
	 */
	public function getGroupIdsByTaskIds(array $taskIds): array;
}
