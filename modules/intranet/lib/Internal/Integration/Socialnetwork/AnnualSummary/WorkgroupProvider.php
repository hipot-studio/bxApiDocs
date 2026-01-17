<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\CollabFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\WorkgroupTable;

class WorkgroupProvider extends AbstractFeatureProvider
{
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)WorkgroupTable::query()
			->where('TYPE', 'collab')
			->where('OWNER_ID', $userId)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): CollabFeature
	{
		return new CollabFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_collab_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}
}
