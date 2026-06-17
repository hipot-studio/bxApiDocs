<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\RepeatSale\Segment\Collector\Ai\AiApproveCollector;
use Bitrix\Crm\RepeatSale\Segment\Collector\Ai\AiScreeningCollector;
use Bitrix\Crm\RepeatSale\Segment\Collector\Ai\RemainingCollector;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\Traits\Singleton;

final class Factory
{
	use Singleton;

	public function getCollector(?SegmentCode $segmentCode): ?BaseCollector
	{
		return match ($segmentCode)
		{
			SegmentCode::LOST_CLIENT => LostClientCollector::getInstance(),
			SegmentCode::SLEEPING_CLIENT => SleepingClientCollector::getInstance(),
			SegmentCode::DEAL_EVERY_YEAR => DealEveryYearCollector::getInstance(),
			SegmentCode::DEAL_EVERY_HALF_YEAR => DealEveryHalfYearCollector::getInstance(),
			SegmentCode::DEAL_EVERY_MONTH => DealEveryMonthCollector::getInstance(),
			SegmentCode::AI_SCREENING => AiScreeningCollector::getInstance(),
			SegmentCode::AI_APPROVE => AiApproveCollector::getInstance(),
			SegmentCode::REMAINING => $this->getRemainingCollector(),
			default => null,
		};
	}

	private function getRemainingCollector(): RemainingCollector
	{
		return RemainingCollector::getInstance()
			->setIsHolidaySegmentEnabled($this->isHolidaySegmentEnabled())
		;
	}

	private function isHolidaySegmentEnabled(): bool
	{
		$segment = RepeatSaleSegmentController::getInstance()
			->getByCode(SegmentCode::AI_SCREENING->value)
		;

		return $segment !== null && $segment->getIsEnabled();
	}
}
