<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Tunnel;

final class CreateEvent extends TunnelBaseEvent
{
	private const EVENT_NAME = 'create';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}
