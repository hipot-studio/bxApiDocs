<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

interface ProjectTagRepositoryInterface
{
	/** @param string[] $tagNames */
	public function save(int $groupId, array $tagNames): void;
}
