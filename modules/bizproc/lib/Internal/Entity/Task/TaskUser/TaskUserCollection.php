<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task\TaskUser;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method TaskUser|null getFirstCollectionItem()
 * @method \ArrayIterator<TaskUser> getIterator()
 */
class TaskUserCollection extends BaseEntityCollection
{
	public function __construct(TaskUser ...$taskUsers)
	{
		foreach ($taskUsers as $taskUser)
		{
			$this->collectionItems[] = $taskUser;
		}
	}

	public static function mapFromArray(array $props): static
	{
		$taskUsers = array_map(
			static function ($taskUser)
			{
				return TaskUser::mapFromArray($taskUser);
			},
			$props
		);

		return new static(...$taskUsers);
	}
}
