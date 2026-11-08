<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\Security\Role\Manage\Entity\WebForm;
use Bitrix\Crm\Security\Role\Model\RoleRelationTable;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAccessTable;
use Bitrix\Main\UserGroupTable;

final class WebFormAccessChecker implements AccessCheckerInterface
{
	private const ADMIN_GROUP_ID = 1;

	public function getAllowedUserIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return [];
		}

		$expiredProvidersByUser = $this->getExpiredProvidersByUser($userIds);

		$allowedUserIds = array_fill_keys($this->getAdminUserIds($userIds), true);
		$allowedRelations = RoleRelationTable::getRelationsWithPermissionsOfType(
			WebForm::ENTITY_CODE,
			UserPermissions::OPERATION_READ,
		);
		if ($allowedRelations !== [])
		{
			$allowedRelationsMap = array_fill_keys($allowedRelations, true);
			foreach ($userIds as $userId)
			{
				if (isset($allowedRelationsMap['U' . $userId]))
				{
					$allowedUserIds[$userId] = true;
				}
			}

			$rows = UserAccessTable::query()
				->setSelect(['USER_ID', 'PROVIDER_ID'])
				->whereIn('USER_ID', $userIds)
				->whereIn('ACCESS_CODE', $allowedRelations)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$userId = (int)$row['USER_ID'];
				$providerId = (string)$row['PROVIDER_ID'];
				if (!isset($expiredProvidersByUser[$userId][$providerId]))
				{
					$allowedUserIds[$userId] = true;
				}
			}
		}

		return array_values(array_filter(
			$userIds,
			static fn(int $userId): bool => isset($allowedUserIds[$userId]),
		));
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array<string, true>>
	 */
	private function getExpiredProvidersByUser(array $userIds): array
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$userIdsSql = implode(',', $userIds);
		$nowSql = $sqlHelper->convertToDbDateTime(new DateTime());
		$rows = $connection->query(
			"SELECT DISTINCT USER_ID, PROVIDER_ID
			FROM b_user_access_check
			WHERE USER_ID IN ({$userIdsSql})
				AND DATE_CHECK <= {$nowSql}",
		);

		$expiredProvidersByUser = [];
		while ($row = $rows->fetch())
		{
			$expiredProvidersByUser[(int)$row['USER_ID']][(string)$row['PROVIDER_ID']] = true;
		}

		return $expiredProvidersByUser;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getAdminUserIds(array $userIds): array
	{
		$rows = UserGroupTable::query()
			->setSelect(['USER_ID', 'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO'])
			->where('GROUP_ID', self::ADMIN_GROUP_ID)
			->whereIn('USER_ID', $userIds)
			->exec()
		;
		$now = time();
		$adminUserIds = [];
		while ($row = $rows->fetch())
		{
			$dateFrom = $row['DATE_ACTIVE_FROM'];
			$dateTo = $row['DATE_ACTIVE_TO'];
			if (
				(!$dateFrom || $dateFrom->getTimestamp() <= $now)
				&& (!$dateTo || $dateTo->getTimestamp() >= $now)
			)
			{
				$adminUserIds[] = (int)$row['USER_ID'];
			}
		}

		return $adminUserIds;
	}
}
