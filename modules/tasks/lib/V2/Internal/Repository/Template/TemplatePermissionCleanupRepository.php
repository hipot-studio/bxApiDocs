<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;

class TemplatePermissionCleanupRepository implements TemplatePermissionCleanupRepositoryInterface
{
	private const GROUP_ACCESS_CODE_PREFIX = 'SG';

	// Role suffixes of a workgroup access code, see AccessCode::AC_SOCNETGROUP
	private const GROUP_ROLE_SUFFIXES = ['_A', '_E', '_K'];

	public function deleteByAccessCodes(array $accessCodes): void
	{
		if (empty($accessCodes))
		{
			return;
		}

		TasksTemplatePermissionTable::deleteList([
			'@ACCESS_CODE' => $accessCodes,
		]);
	}

	public function deleteByGroupId(int $groupId): void
	{
		if ($groupId <= 0)
		{
			return;
		}

		$this->deleteByAccessCodes($this->getGroupAccessCodes($groupId));
	}

	/**
	 * @return string[]
	 */
	private function getGroupAccessCodes(int $groupId): array
	{
		$accessCode = self::GROUP_ACCESS_CODE_PREFIX . $groupId;

		$accessCodes = [$accessCode];
		foreach (self::GROUP_ROLE_SUFFIXES as $suffix)
		{
			$accessCodes[] = $accessCode . $suffix;
		}

		return $accessCodes;
	}
}
