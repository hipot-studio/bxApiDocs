<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;
use Bitrix\Tasks\V2\Internal\Entity;

class TaskMemberMapper
{
	public function mapToCollection(array $members): Entity\UserCollection
	{
		$entities = [];
		foreach ($members as $member)
		{
			$entities[] = $this->mapToEntity($member);
		}

		return new Entity\UserCollection(...$entities);
	}

	public function mapToEntity(array $member): Entity\User
	{
		return new Entity\User(
			id: (int)$member['USER_ID'],
			role: $member['TYPE'],
		);
	}

	public function mapToTaskMemberCollection(array $members): Entity\TaskMemberCollection
	{
		$entities = [];
		foreach ($members as $member)
		{
			$entities[] = $this->mapToTaskMemberEntity($member);
		}

		return new Entity\TaskMemberCollection(...$entities);
	}

	public function mapToTaskMemberEntity(array $member): Entity\TaskMember
	{
		return new Entity\TaskMember(
			taskId: (int)$member['TASK_ID'],
			userId: (int)$member['USER_ID'],
			type: $member['TYPE'],
		);
	}
}