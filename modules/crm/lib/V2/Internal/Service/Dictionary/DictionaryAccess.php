<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Dictionary;

use Bitrix\Crm\Service\Container;

/**
 * The permission model of the dictionary domain, whole and in one place.
 *
 * TWO RIGHTS, AND BOTH ARE PORTAL WIDE. Reading a dictionary follows the right to read items of the
 * CRM at all, writing one follows CRM administrator. That is the parity of the slice being transferred:
 * the static helpers of the legacy facade compute exactly these two checks and never look at the value
 * they are handed ({@see \CCrmStatus::CheckReadPermission()}, {@see \CCrmStatus::CheckCreatePermission()}).
 * The helpers themselves are not called - a permission of this domain is asked of `UserPermissions`
 * directly, the way the funnel domain does it ({@see \Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess}).
 *
 * NEITHER THE DICTIONARY NOR THE VALUE IS PART OF A CHECK, so both methods take the user and nothing
 * else: there is no permission of a single dictionary value anywhere in the CRM, and a caller allowed to
 * write one of the seven dictionaries is allowed to write all of them.
 *
 * A user id of zero or less is refused rather than resolved: this domain has no current user of its own,
 * and an operation always names the user it is performed as.
 *
 * @internal
 */
final class DictionaryAccess
{
	/** Whether $userId may read the values of the dictionaries. */
	public function canRead(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions($userId)
			->entityType()
			->canReadSomeItemsInCrmOrAutomatedSolutions()
		;
	}

	/** Whether $userId may add, change or erase a value of the dictionaries. */
	public function canWrite(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return Container::getInstance()->getUserPermissions($userId)->isCrmAdmin();
	}
}
