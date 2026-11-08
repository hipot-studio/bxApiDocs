<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Param;

/**
 * Reading rules of the order of the attachments (ALG-02). The order names ids, not the composition: the composition
 * lives in FILE_ID and has writers of its own, so a file the order does not know about is never lost and an id the
 * composition no longer keeps is never shown.
 */
class FileOrderRule
{
	/**
	 * @param int[] $fileIds
	 * @param int[]|null $fileOrder
	 * @return int[]
	 */
	public static function orderedFileIds(array $fileIds, ?array $fileOrder): array
	{
		if (empty($fileOrder))
		{
			return $fileIds;
		}

		$known = array_values(array_intersect($fileOrder, $fileIds));
		$rest = array_values(array_diff($fileIds, $known));

		return array_merge($known, $rest);
	}
}
