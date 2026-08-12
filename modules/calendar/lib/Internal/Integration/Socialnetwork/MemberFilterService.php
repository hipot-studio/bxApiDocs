<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Integration\Socialnetwork;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class MemberFilterService
{
	/**
	 * @param int[] $memberIds
	 * @return int[]
	 */
	public function filterAccessibleMemberIds(array $memberIds): array
	{
		if (empty($memberIds))
		{
			return [];
		}

		$currentUserId = CurrentUser::get()->getId();

		if (!$currentUserId || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$memberIds = array_unique($memberIds);

		$usersById = $this->loadUsers($memberIds);

		$result = [];

		foreach ($memberIds as $memberId)
		{
			if (
				isset($usersById[$memberId])
				&& \CSocNetUser::CanProfileView($currentUserId, $usersById[$memberId])
			)
			{
				$result[] = $memberId;
			}
		}

		return $result;
	}

	/**
	 * @param int[] $memberIds
	 * @return array<int, array>
	 */
	private function loadUsers(array $memberIds): array
	{
		$usersById = [];

		// Handlers of OnGetProfileView event can use any user's fields and UF-fields.
		// We should select all fields
		$dbUsers = \CUser::GetList(
			'ID',
			'ASC',
			['ID' => implode('|', $memberIds)],
			['SELECT' => ['UF_*']],
		);

		while ($user = $dbUsers->Fetch())
		{
			$usersById[(int)$user['ID']] = $user;
		}

		return $usersById;
	}
}
