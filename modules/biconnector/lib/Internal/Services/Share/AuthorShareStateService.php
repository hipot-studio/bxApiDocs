<?php

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\Main\UserTable;

/**
 * State of the author's own links for the share popup.
 */
class AuthorShareStateService
{
	public function __construct(
		private readonly SupersetDashboardShareRepository $repository,
		private readonly SupersetDashboardShareBanRepository $banRepository,
	)
	{
	}

	/**
	 * States of the author's own links to several dashboards, read with a fixed number of queries.
	 *
	 * @param int[] $dashboardIds
	 * @param bool $withControlState Whether to report the ban and the switch-off by an administrator.
	 * @return array<int, array{
	 *     isActive: bool,
	 *     password: string,
	 *     dateExpireTimestamp: int,
	 *     isBanned?: bool,
	 *     disabledByAdmin?: ?array{id: int, name: string, dateTimestamp: ?int},
	 * }> Dashboard id => state; dashboards without the author's link are absent.
	 */
	public function getStates(array $dashboardIds, int $userId, bool $withControlState): array
	{
		$shares = $this->repository->getByDashboardIdsAndUser($dashboardIds, $userId);
		if (empty($shares))
		{
			return [];
		}

		$states = [];
		foreach ($shares as $dashboardId => $share)
		{
			$states[$dashboardId] = [
				'isActive' => $share->isValid(),
				'password' => $share->getPassword(),
				'dateExpireTimestamp' => $share->getDateExpire()->getTimestamp(),
			];
		}

		if (!$withControlState)
		{
			return $states;
		}

		$bannedIds = array_flip($this->banRepository->getBannedDashboardIds(array_keys($shares)));
		$adminNames = $this->getUserNames(array_filter(array_map(
			fn(SupersetDashboardShare $share) => $this->getAdminDisablerId($share),
			$shares,
		)));

		foreach ($shares as $dashboardId => $share)
		{
			$adminId = $this->getAdminDisablerId($share);
			$states[$dashboardId]['isBanned'] = isset($bannedIds[$dashboardId]);
			$states[$dashboardId]['disabledByAdmin'] = $adminId === null ? null : [
				'id' => $adminId,
				'name' => $adminNames[$adminId] ?? '',
				'dateTimestamp' => $share->getEffectiveDateDisabled()?->getTimestamp(),
			];
		}

		return $states;
	}

	private function getAdminDisablerId(SupersetDashboardShare $share): ?int
	{
		return $share->isDisabledByAdmin() ? $share->getDisabledById() : null;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string> Not escaped.
	 */
	private function getUserNames(array $userIds): array
	{
		$userIds = array_values(array_unique($userIds));
		if (empty($userIds))
		{
			return [];
		}

		$users = UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL'],
			'filter' => ['@ID' => $userIds],
		]);

		$nameFormat = \CSite::GetNameFormat(false);
		$names = [];
		while ($user = $users->fetch())
		{
			$names[(int)$user['ID']] = \CUser::FormatName($nameFormat, $user, true, false);
		}

		return $names;
	}
}
