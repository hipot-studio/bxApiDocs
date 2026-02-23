<?php

namespace Bitrix\Landing\Update\Domain;

use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Site\Type;
use Bitrix\Main\Config\Option;

class TypeUpdater
{
	private const OPTION_CODE = 'last_site_id_whose_domain_updated';
	private const ROW_LIMIT = 10;

	public static function updateType(): ?string
	{
		$start = microtime(true);

		$siteController = Manager::getExternalSiteController();
		if (
			empty($siteController)
			|| !is_callable([$siteController, 'updateTypeDomain'])
		)
		{
			return '';
		}

		$lastId = Option::get('landing', self::OPTION_CODE, 0);

		$siteCode = '/' . Type::PSEUDO_SCOPE_CODE_FORMS . '/';
		$resSite = SiteTable::getList([
			'select' => [
				'ID',
				'CODE',
				'DOMAIN_ID',
				'DOMAIN.DOMAIN',
			],
			'filter' => [
				'=CODE' => $siteCode,
				'>ID' => $lastId,
			],
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => self::ROW_LIMIT,
		]);

		$limitCount = 0;
		while ($row = $resSite->fetch())
		{
			$limitCount++;
			if (!empty($row['LANDING_INTERNALS_SITE_DOMAIN_DOMAIN']))
			{
				$timeLimit = (int)((int)ini_get('max_execution_time') * 0.9) ?: 50;
				if (microtime(true) - $start > $timeLimit)
				{
					return __CLASS__ . '::' . __FUNCTION__ . '();';
				}

				$siteController::updateTypeDomain($row['LANDING_INTERNALS_SITE_DOMAIN_DOMAIN'], 'form');

				Option::set('landing', self::OPTION_CODE, $row['ID']);
			}
		}

		if ($limitCount > self::ROW_LIMIT -1)
		{
			return __CLASS__ . '::' . __FUNCTION__ . '();';
		}

		Option::delete('landing', ['name' => self::OPTION_CODE]);

		return '';
	}
}
