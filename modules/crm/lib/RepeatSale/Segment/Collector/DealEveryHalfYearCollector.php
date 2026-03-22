<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

final class DealEveryHalfYearCollector extends BasePeriodCollector
{
	protected function getIntervals(): array
	{
		return ['-6 months', '-1 year'];
	}
}
