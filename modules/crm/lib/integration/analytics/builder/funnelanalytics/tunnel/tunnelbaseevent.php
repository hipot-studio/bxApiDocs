<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Tunnel;

use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\FunnelAnalyticsBaseEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;

abstract class TunnelBaseEvent extends FunnelAnalyticsBaseEvent
{

	abstract public function getEventName(): string;

	protected function getType(): string
	{
		return Dictionary::TYPE_TUNNEL;
	}
}
