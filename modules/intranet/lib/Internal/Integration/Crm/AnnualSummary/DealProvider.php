<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Crm\AnnualSummary;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\DealFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class DealProvider extends AbstractFeatureProvider
{
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return Container::getInstance()
			->getFactory(\CCrmOwnerType::Deal)
			->getItemsCountFilteredByPermissions([
				'=STAGE_SEMANTIC_ID' => PhaseSemantics::SUCCESS,
				'>=DATE_CREATE' => $from,
				'<DATE_CREATE' => $to,
				'ASSIGNED_BY_ID' => $userId,
			], $userId)
		;
	}

	public function createFeature(int $value): DealFeature
	{
		return new DealFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_deal_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('crm');
	}
}
