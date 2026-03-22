<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class ScreeningRepeatSaleItemEvent extends AIBaseEvent
{
	protected function getEvent(): string
	{
		return Dictionary::EVENT_SCREENING_REPEAT_SALE_ITEM;
	}
}

