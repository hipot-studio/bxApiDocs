<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Funnel;

final class CreateEvent extends FunnelBaseEvent
{
	private const EVENT_NAME = 'create';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}
