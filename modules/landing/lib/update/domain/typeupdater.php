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
	private const TYPE_CODE = 'form';

	public static function updateType(): ?string
	{
		$start = microtime(true);
		$timeLimit = self::resolveTimeLimit();
		$siteController = Manager::getExternalSiteController();
		if (
			empty($siteController)
			|| !is_callable([$siteController, 'updateTypeDomain'])
		)
		{
			return '';
		}

		$lastId = (int)Option::get('landing', self::OPTION_CODE, 0);
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

		$processedCount = 0;
		while ($row = $resSite->fetch())
		{
			$processedCount++;
			if (!empty($row['LANDING_INTERNALS_SITE_DOMAIN_DOMAIN']))
			{
				if (microtime(true) - $start > $timeLimit)
				{
					return __CLASS__ . '::' . __FUNCTION__ . '();';
				}

				$siteController::updateTypeDomain(
					$row['LANDING_INTERNALS_SITE_DOMAIN_DOMAIN'],
					self::TYPE_CODE,
				);

				Option::set('landing', self::OPTION_CODE, $row['ID']);
			}
		}

		if ($processedCount >= self::ROW_LIMIT)
		{
			return __CLASS__ . '::' . __FUNCTION__ . '();';
		}

		Option::delete('landing', ['name' => self::OPTION_CODE]);

		return '';
	}

	private static function resolveTimeLimit(): int
	{
		$maxExecutionTime = (int)ini_get('max_execution_time');
		if ($maxExecutionTime <= 0)
		{
			return 50;
		}

		return (int)($maxExecutionTime * 0.9);
	}
}
