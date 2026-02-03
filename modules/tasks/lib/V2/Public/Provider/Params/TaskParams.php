<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class TaskParams
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $taskId,
		public readonly int $userId,
		public readonly bool $group = true,
		public readonly bool $flow = true,
		public readonly bool $stage = true,
		public readonly bool $members = true,
		public readonly bool $checkLists = true,
		public readonly bool $tags = true,
		public readonly bool $crm = true,
		public readonly bool $email = true,
		public readonly bool $subTasks = true,
		public readonly bool $relatedTasks = true,
		public readonly bool $gantt = true,
		public readonly bool $placements = true,
		public readonly bool $containsCommentFiles = true,
		public readonly bool $favorite = true,
		public readonly bool $options = true,
		public readonly bool $parameters = true,
		public readonly bool $results = true,
		public readonly bool $reminders = true,
		public readonly bool $userFields = true,
		public readonly bool $checkTaskAccess = true,
		public readonly bool $checkGroupAccess = true,
		public readonly bool $checkFlowAccess = true,
		public readonly bool $checkCrmAccess = true,
		public readonly bool $checkParentAccess = true,
		public readonly bool $view = false,
		public readonly bool $scenarios = true,
	)
	{

	}

	public static function mapFromIds(int $taskId, int $userId, array $select = []): static
	{
		$select = [...$select, 'taskId' => $taskId, 'userId' => $userId];

		return static::mapFromArray($select);
	}

	public static function mapFromArray(?array $select): static
	{
		if ($select === null)
		{
			return new static(taskId: 0, userId: 0);
		}

		return new static(
			taskId: static::mapInteger($select, 'taskId', 0),
			userId: static::mapInteger($select, 'userId', 0),
			group: static::mapBool($select, 'group', false),
			flow: static::mapBool($select, 'flow', false),
			stage: static::mapBool($select, 'stage', false),
			members: static::mapBool($select, 'members', false),
			checkLists: static::mapBool($select, 'checkLists', false),
			tags: static::mapBool($select, 'tags', false),
			crm: static::mapBool($select, 'crm', false),
			email: static::mapBool($select, 'email', false),
			subTasks: static::mapBool($select, 'subTasks', false),
			relatedTasks: static::mapBool($select, 'relatedTasks', false),
			gantt: static::mapBool($select, 'gantt', false),
			containsCommentFiles: static::mapBool($select, 'containsCommentFiles', false),
			favorite: static::mapBool($select, 'favorite', false),
			options: static::mapBool($select, 'options', false),
			parameters: static::mapBool($select, 'parameters', false),
			results: static::mapBool($select, 'results', false),
			userFields: static::mapBool($select, 'userFields', false),
			scenarios: static::mapBool($select, 'scenarios', false),
		);
	}
}
