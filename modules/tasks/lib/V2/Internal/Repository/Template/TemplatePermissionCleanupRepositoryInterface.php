<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

interface TemplatePermissionCleanupRepositoryInterface
{
	/**
	 * @param string[] $accessCodes
	 */
	public function deleteByAccessCodes(array $accessCodes): void;

	public function deleteByGroupId(int $groupId): void;
}
