<?php

namespace Bitrix\Crm\RepeatSale\Segment\Entity;

class RepeatSaleSegment extends EO_RepeatSaleSegment
{
	public function isChildren(): bool
	{
		return $this->getBaseSegmentCode() !== null;
	}
}
