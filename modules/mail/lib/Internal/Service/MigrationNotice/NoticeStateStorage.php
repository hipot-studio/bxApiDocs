<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

use Bitrix\Mail\Helper\Config\Guide;

/** Stores whether a person has seen the general announcement. */
class NoticeStateStorage
{
	public const ANNOUNCEMENT_USER_OPTION_NAME = 'migration_announcement_shown';

	private const FLAG_VALUE = 'Y';
	public function wasAnnouncementShown(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return \CUserOptions::GetOption(
			Guide::USER_OPTION_CATEGORY,
			self::ANNOUNCEMENT_USER_OPTION_NAME,
			null,
			$userId,
		) === self::FLAG_VALUE;
	}

	public function markAnnouncementShown(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		\CUserOptions::SetOption(
			Guide::USER_OPTION_CATEGORY,
			self::ANNOUNCEMENT_USER_OPTION_NAME,
			self::FLAG_VALUE,
			false,
			$userId,
		);
	}

}
