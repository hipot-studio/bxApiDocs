<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;

final class SystemGroupLocalizationService
{
	public static function update(?string $langCode): void
	{
		$groupCollection = SupersetDashboardGroupTable::getList([
			'filter' => [
				'=TYPE' =>  SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM,
			],
		])
			->fetchCollection()
		;

		foreach ($groupCollection as $group)
		{
			$groupName = AccessInstaller::getDefaultGroupName($group->getCode(), $langCode);

			if (empty($groupName))
			{
				continue;
			}

			$group->setName($groupName);
		}

		$groupCollection->save();
	}
}
