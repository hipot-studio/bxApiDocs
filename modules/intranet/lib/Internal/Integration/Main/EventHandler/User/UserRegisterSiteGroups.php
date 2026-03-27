<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main\EventHandler\User;

use CGroup;

class UserRegisterSiteGroups
{
	public static function addEmployeeSiteGroups(array &$fields): void
	{
		$siteId = $fields['SITE_ID'] ?? (defined('SITE_ID') ? SITE_ID : null);

		if ($siteId && ($group = CGroup::GetList('c_sort', 'asc', ['STRING_ID' => 'EMPLOYEES_' . $siteId])->Fetch()))
		{
			$fields['GROUP_ID'] = array_unique(
				array_merge($fields['GROUP_ID'] ?? [], [(int)$group['ID']])
			);
		}
	}
}