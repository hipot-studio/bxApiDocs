<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Funnel;

use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\FunnelAnalyticsBaseEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;

abstract class FunnelBaseEvent extends FunnelAnalyticsBaseEvent
{
	abstract public function getEventName(): string;

	protected function getType(): string
	{
		return Dictionary::TYPE_FUNNEL;
	}
}
