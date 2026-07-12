<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

class WorkgroupTagService
{
	public function updateTags(int $groupId, array $tags): Result
	{
		$result = new Result();

		$cleanTags = array_map('trim', $tags);
		$cleanTags = array_filter($cleanTags, static fn(string $tag): bool => $tag !== '');
		$keywords = implode(',', array_values($cleanTags));

		if (!\CSocNetGroup::Update($groupId, ['KEYWORDS' => $keywords]))
		{
			$result->addError(new Error('Failed to update tags'));
		}

		return $result;
	}
}
