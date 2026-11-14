<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\Socialnetwork\WorkgroupTagTable;
use Bitrix\Socialnetwork\WorkgroupTable;

class ProjectTagNormalizer
{
	/** @param string[] $tagNames */
	public function normalize(array $tagNames): Result
	{
		$result = new Result();
		$tags = $this->prepareTags($tagNames);
		if ($tags === null)
		{
			return $result->addError(
				new Error('Failed to save project tags', 'PROJECT_TAG_SAVE_FAILED'),
			);
		}

		return $result->setData(['tagNames' => $tags]);
	}

	private function prepareTags(array $tagNames): ?array
	{
		$tags = WorkgroupTagTable::prepareTagsForSave($tagNames);
		if ($tags === null)
		{
			return null;
		}

		$keywords = implode(',', $tags);
		if (mb_strlen(Emoji::encode($keywords)) > WorkgroupTable::KEYWORDS_MAX_LENGTH)
		{
			return null;
		}

		return $tags;
	}
}
