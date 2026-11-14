<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Main\SiteTable;

class SiteHelper
{
	private static bool $isResolved = false;
	private static ?string $siteId = null;

	public static function getSiteId(): ?string
	{
		if (!self::$isResolved)
		{
			self::$siteId = self::resolveDefaultSiteId();
			self::$isResolved = true;
		}

		return self::$siteId;
	}

	private static function resolveDefaultSiteId(): ?string
	{
		$site = SiteTable::getList([
			'select' => ['LID'],
			'filter' => [
				'=DEF' => 'Y',
				'=ACTIVE' => 'Y',
			],
			'order' => ['SORT' => 'ASC'],
			'limit' => 1,
		])->fetch();

		if (!is_array($site))
		{
			return null;
		}

		return (string)$site['LID'];
	}
}
