<?php

namespace Bitrix\BIConnector\Superset\Install;

use Bitrix\Main\SiteTemplateTable;

final class SiteTemplateInstaller
{
	private const RULES = [
		[
			'SORT' => 500,
			'CONDITION' => "CSite::InDir('/bi/dashboard/detail/')",
			'TEMPLATE' => 'dashboard_detail',
		],
		[
			'SORT' => 500,
			'CONDITION' => "CSite::InDir('/bi/dashboard/demo/')",
			'TEMPLATE' => 'dashboard_detail',
		],
	];

	public static function install(): bool
	{
		$siteId = \CSite::GetDefSite();
		if (!$siteId)
		{
			return false;
		}

		$isSuccess = true;

		foreach (self::RULES as $rule)
		{
			$rule['SITE_ID'] = $siteId;

			$installedRule = SiteTemplateTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=SITE_ID' => $rule['SITE_ID'],
					'=CONDITION' => $rule['CONDITION'],
					'=TEMPLATE' => $rule['TEMPLATE'],
				],
				'limit' => 1,
			])->fetch();

			if ($installedRule)
			{
				continue;
			}

			if (!SiteTemplateTable::add($rule)->isSuccess())
			{
				$isSuccess = false;
			}
		}

		return $isSuccess;
	}

	public static function uninstall(): bool
	{
		$isSuccess = true;

		foreach (self::RULES as $rule)
		{
			$installedRules = SiteTemplateTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=CONDITION' => $rule['CONDITION'],
					'=TEMPLATE' => $rule['TEMPLATE'],
				],
			]);

			while ($installedRule = $installedRules->fetch())
			{
				if (!SiteTemplateTable::delete($installedRule['ID'])->isSuccess())
				{
					$isSuccess = false;
				}
			}
		}

		return $isSuccess;
	}
}
