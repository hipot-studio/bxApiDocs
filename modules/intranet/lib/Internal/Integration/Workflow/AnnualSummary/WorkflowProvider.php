<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Workflow\AnnualSummary;

use Bitrix\Bizproc\WorkflowStateTable;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\WorkflowFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class WorkflowProvider extends AbstractFeatureProvider
{
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)WorkflowStateTable::query()
			->where('STARTED_BY', $userId)
			->where('STARTED', '>=', $from)
			->where('STARTED', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): WorkflowFeature
	{
		return new WorkflowFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_workflow_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('bizproc');
	}
}
