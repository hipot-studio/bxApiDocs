<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\WorkgroupTagTable;

class ProjectTagRepository implements ProjectTagRepositoryInterface
{
	public function validate(array $tagNames): Result
	{
		$result = new Result();

		if (WorkgroupTagTable::prepareTagsForSave($tagNames) === null)
		{
			return $result->addError(
				new Error('Failed to save project tags', 'PROJECT_TAG_SAVE_FAILED'),
			);
		}

		return $result;
	}

	public function save(int $groupId, array $tagNames): Result
	{
		$result = new Result();

		if (!WorkgroupTagTable::set(['groupId' => $groupId, 'tags' => $tagNames]))
		{
			return $result->addError(
				new Error('Failed to save project tags', 'PROJECT_TAG_SAVE_FAILED'),
			);
		}

		return $result;
	}
}
