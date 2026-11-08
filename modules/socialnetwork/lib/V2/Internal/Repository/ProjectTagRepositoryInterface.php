<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\Result;

interface ProjectTagRepositoryInterface
{
	/** @param string[] $tagNames */
	public function validate(array $tagNames): Result;

	/** @param string[] $tagNames */
	public function save(int $groupId, array $tagNames): Result;
}
