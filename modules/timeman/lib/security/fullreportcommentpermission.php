<?php

namespace Bitrix\Timeman\Security;

final class FullReportCommentPermission
{
	public static function canAdd(
		int $currentUserId,
		int $reportOwnerId,
		array $writableUserIds,
		bool $isAdmin
	): bool
	{
		if ($isAdmin || $currentUserId === $reportOwnerId || in_array('*', $writableUserIds, true))
		{
			return true;
		}

		return in_array($reportOwnerId, array_map('intval', $writableUserIds), true);
	}
}
