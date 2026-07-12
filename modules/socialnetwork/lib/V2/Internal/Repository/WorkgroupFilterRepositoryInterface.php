<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

interface WorkgroupFilterRepositoryInterface
{
	/**
	 * @return int[]
	 */
	public function getGroupIdsByTag(string $tag): array;

	/**
	 * @return int[]
	 */
	public function getExtranetGroupIds(): array;
}
