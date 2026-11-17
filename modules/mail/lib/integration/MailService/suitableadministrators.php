<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Integration\Intranet\UserService;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;

/**
 * The administrators of the portal a summary of mailservice is told to.
 *
 * Suitable is an active administrator im keeps a system chat for: an internal user or a replica, and of the external
 * types nobody else, a guest of im among them. An administrator is a member of group 1 at the moment of the call.
 */
final class SuitableAdministrators
{
	private const SUITABLE_EXTERNAL_TYPES = ['replica'];
	private const ADMINISTRATORS_GROUP_ID = 1;

	/** @return list<int> in the order of the list of administrators */
	public function ids(): array
	{
		$administrators = [];
		foreach (UserService::getAdminUserIds() as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$administrators[$userId] = $userId;
			}
		}
		if ($administrators === [])
		{
			return [];
		}

		$unsuitableTypes = array_values(array_diff(
			UserTable::getExternalUserTypes(),
			self::SUITABLE_EXTERNAL_TYPES,
		));
		$suitable = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', array_values($administrators))
			->whereIn('ID', $this->membersOfTheAdministratorsGroupNow())
			->where('ACTIVE', 'Y')
			->where(
				Query::filter()
					->logic('or')
					->whereNull('EXTERNAL_AUTH_ID')
					->whereNotIn('EXTERNAL_AUTH_ID', $unsuitableTypes)
			)
			->fetchAll()
		;
		$suitable = array_flip(array_map('intval', array_column($suitable, 'ID')));

		return array_values(array_filter(
			$administrators,
			static fn(int $userId): bool => isset($suitable[$userId]),
		));
	}

	/**
	 * The list of administrators intranet hands over is cached for a day, and nothing clears it when a term in the
	 * group runs out, so the term is weighed here at the moment of the call.
	 */
	private function membersOfTheAdministratorsGroupNow(): Query
	{
		$now = new DateTime();

		return UserGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', self::ADMINISTRATORS_GROUP_ID)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('DATE_ACTIVE_FROM')
					->where('DATE_ACTIVE_FROM', '<=', $now)
			)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('DATE_ACTIVE_TO')
					->where('DATE_ACTIVE_TO', '>=', $now)
			)
		;
	}
}
