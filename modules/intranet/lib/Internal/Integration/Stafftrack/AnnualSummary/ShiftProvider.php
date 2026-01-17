<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Stafftrack\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\CheckInFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Model\ShiftTable;

class ShiftProvider extends AbstractFeatureProvider
{
	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)ShiftTable::query()
			->where('USER_ID', $userId)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): CheckInFeature
	{
		return new CheckInFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_checkin_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('stafftrack');
	}
}
