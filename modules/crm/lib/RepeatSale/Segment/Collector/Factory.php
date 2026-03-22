<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

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
			default => null,
		};
	}
}
