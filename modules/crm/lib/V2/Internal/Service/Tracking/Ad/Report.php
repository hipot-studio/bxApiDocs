<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking\Ad;

use Bitrix\Crm\Tracking\Ad\ReportBuilder;
use Bitrix\Crm\Tracking\Internals\SourceExpensesTable;
use Bitrix\Crm\Tracking\Internals\SourceTable;
use Bitrix\Crm\Tracking\Source\Level\Type;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

final class Report
{
	public function assertAvailable(): void
	{
		if (Loader::includeModule('bitrix24') && !\Bitrix\Bitrix24\Feature::isFeatureEnabled('crm_tracking_reports'))
		{
			throw Refusal::AccessDenied->exception();
		}
	}

	public function source(int $sourceId): array
	{
		$this->assertAvailable();
		$source = $sourceId > 0 ? SourceTable::getById($sourceId)->fetch() : false;
		if (!$source)
		{
			throw Refusal::SourceNotFound->exception();
		}

		return $source;
	}

	public function builder(int $sourceId, Period $period): ReportBuilder
	{
		$this->source($sourceId);

		return (new ReportBuilder())->setSourceId($sourceId)->setPeriod(
			new Date($period->dateFrom, 'Y-m-d'),
			new Date($period->dateTo, 'Y-m-d'),
		);
	}

	public function pageChildIds(
		int $sourceId,
		Period $period,
		int $level,
		?int $parentId,
		int $lastId,
		int $limit,
	): array
	{
		$prefix = match ($level) {
			Type::Keyword => 'SOURCE_CHILD',
			Type::Adgroup => 'SOURCE_CHILD.PARENT',
			Type::Campaign => 'SOURCE_CHILD.PARENT.PARENT',
			default => throw Refusal::OperationConflict->exception(),
		};
		$query = SourceExpensesTable::query()
			->setDistinct(true)
			->addSelect("{$prefix}.ID", 'CHILD_ID')
			->where('SOURCE_ID', $sourceId)
			->where('DATE_STAT', '>=', new Date($period->dateFrom, 'Y-m-d'))
			->where('DATE_STAT', '<=', new Date($period->dateTo, 'Y-m-d'))
			->where('TYPE_ID', SourceExpensesTable::TYPE_AD)
			->where('SOURCE_CHILD.LEVEL', Type::Keyword)
			->where("{$prefix}.LEVEL", $level)
			->where("{$prefix}.ID", '>', $lastId)
			->setOrder(['CHILD_ID' => 'ASC'])
			->setLimit($limit + 1)
		;
		if ($parentId !== null && $level !== Type::Campaign)
		{
			$query->where("{$prefix}.PARENT_ID", $parentId);
		}
		$ids = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['CHILD_ID'];
		}

		return $ids;
	}

	public function status(ReportBuilder $builder): array
	{
		return ['complete' => $builder->isComplete(), 'label' => $builder->getCompleteLabel()];
	}
}
