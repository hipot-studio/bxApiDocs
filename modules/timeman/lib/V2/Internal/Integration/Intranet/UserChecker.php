<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Integration\Intranet;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

class UserChecker
{
	public function isIntranet(int $userId): bool
	{
		if (!$this->isModuleInstalled())
		{
			return false;
		}

		return $this->createEmpty($userId)->isIntranet();
	}

	public function isExtranet(int $userId): bool
	{
		if (!$this->isModuleInstalled())
		{
			return false;
		}

		return $this->createEmpty($userId)->isExtranet();
	}

	/**
	 * @param array<int, int> $userIds
	 * @return array<int, int>
	 */
	public function excludeExtranetUserIds(array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if (empty($userIds) || !Loader::includeModule('extranet'))
		{
			return $userIds;
		}

		$extranetUserIds = array_column(
			UserTable::query()
				->setSelect(['ID'])
				->addFilter('@ID', $userIds)
				->addFilter('=ACTIVE', 'Y')
				->addFilter('GROUPS.GROUP_ID', \CExtranet::GetExtranetUserGroupID())
				->exec()
				->fetchAll(),
			'ID',
		);

		$extranetUserIds = array_flip($this->normalizeUserIds($extranetUserIds));

		return array_values(array_filter(
			$userIds,
			static fn (int $id): bool => !isset($extranetUserIds[$id]),
		));
	}

	private function isModuleInstalled(): bool
	{
		return Loader::includeModule('intranet');
	}

	private function createEmpty(int $userId): User
	{
		return new User(
			id: $userId,
		);
	}

	/**
	 * @param array<int, mixed> $userIds
	 * @return array<int, int>
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$userIds = array_map('intval', $userIds);

		return array_values(array_unique(array_filter(
			$userIds,
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
