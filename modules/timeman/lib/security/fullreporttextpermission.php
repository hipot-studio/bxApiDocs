<?php

namespace Bitrix\Timeman\Security;

final class FullReportTextPermission
{
	public static function canEdit(
		int $currentUserId,
		int $reportOwnerId,
		string $mark,
		array $writableUserIds,
		bool $isAdmin
	): bool
	{
		return $mark === 'X'
			&& (
				in_array('*', $writableUserIds)
				|| in_array($reportOwnerId, $writableUserIds)
				|| $isAdmin
				|| $reportOwnerId === $currentUserId
			)
		;
	}
}
