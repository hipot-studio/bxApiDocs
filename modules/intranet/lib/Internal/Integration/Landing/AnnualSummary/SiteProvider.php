<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Landing\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\SiteFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Type\DateTime;

class SiteProvider extends AbstractFeatureProvider
{
	/**
	 * @throws LoaderException
	 * @throws \Exception
	 */
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)SiteTable::query()
			->where('CREATED_BY_ID', $userId)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): SiteFeature
	{
		return new SiteFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_site_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('landing');
	}
}