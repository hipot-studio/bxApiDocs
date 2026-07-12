<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Convert;

enum ConvertStatus: string
{
	case InProgressFromGroup = 'in_progress_from_group';
	case InProgressFromCollab = 'in_progress_from_collab';

	case StoppedByErrorFromGroup = 'stopped_by_error_from_group';
	case StoppedByErrorFromCollab = 'stopped_by_error_from_collab';

	case CompletedFromGroup = 'completed_from_group';
	case CompletedFromCollab = 'completed_from_collab';

	case NotRequired = 'not_required';

	public function isConverted(): bool
	{
		return match ($this)
		{
			self::NotRequired,
			self::CompletedFromGroup,
			self::CompletedFromCollab => true,
			default => false,
		};
	}
}
