<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\Driver;

/**
 * Whether the hint about the employee limits has already been shown to an administrator.
 *
 * The queue of banners the portal shares keeps nothing between requests, so the module remembers
 * the showing itself. The fact is personal: one administrator having seen the hint says nothing
 * about the others, and the hint is shown to each of them once.
 */
final class QuotaOnboarding
{
	private const OPTION_NAME = 'quota_onboarding_shown';

	public static function isShown(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return (bool)\CUserOptions::getOption(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, false, $userId);
	}

	/**
	 * Writing the same fact twice writes the same fact: a hint shown again changes nothing.
	 */
	public static function markShown(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		\CUserOptions::setOption(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, true, false, $userId);
	}
}
