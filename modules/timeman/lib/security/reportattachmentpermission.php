<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Security;

final class ReportAttachmentPermission
{
	public function canManage(int $reportOwnerId, int $currentUserId, array $writeAccess): bool
	{
		if ($reportOwnerId === $currentUserId)
		{
			return true;
		}

		foreach ($writeAccess as $accessCode)
		{
			if ($accessCode === '*')
			{
				return true;
			}

			if (
				(is_int($accessCode) && $accessCode === $reportOwnerId)
				|| (is_string($accessCode) && (string)$reportOwnerId === $accessCode)
			)
			{
				return true;
			}
		}

		return false;
	}
}
